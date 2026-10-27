<?php

// This bootstrap verifies loopback port 33317 and the worktree-owned MariaDB data directory.
try { require __DIR__.'/failed_delivery_fixture.php'; }
catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage().PHP_EOL); exit(1); }
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, (string) $exception.PHP_EOL);
    exit(1);
});

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\ShipmentLeg;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use App\Services\Logistics\ParcelIncidentRecoveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function parcelCheck(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

function parcelDatabase(string $name): void
{
    parcelCheck((bool) preg_match('/^solespace_parcel_test_[a-f0-9]{16}$/D', $name), 'Invalid disposable schema identity.');
    config(['database.connections.mysql.database' => $name]);
    DB::purge('mysql');
    parcelCheck(DB::selectOne('SELECT DATABASE() AS name')->name === $name, 'Unexpected schema.');
}

if (($argv[1] ?? '') === '--worker') {
    $payload = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    parcelDatabase($payload['database']);
    $worker = (int) $argv[3];
    file_put_contents($payload['barrier'].'-ready-'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (count(glob($payload['barrier'].'-ready-*')) !== 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Concurrent worker barrier timed out.');
        usleep(10000);
    }
    $held = false;
    DB::listen(function ($query) use (&$held) {
        if (! $held && str_contains(strtolower($query->sql), 'shop_owners') && str_contains(strtolower($query->sql), 'for update')) {
            $held = true;
            usleep(250000);
        }
    });
    try {
        $incident = DeliveryIncident::findOrFail($payload['incident']);
        $actor = User::findOrFail($payload['actors'][$worker - 1]);
        if ($payload['operation'] === 'activation') {
            if ($worker === 1) {
                app(\App\Services\Logistics\ShipmentLegService::class)->markInTransit($incident->leg,
                    $incident->leg->assignments()->latest('id')->firstOrFail()->riderProfile);
            } else {
                app(DeliveryIncidentService::class)->resolve($incident, $incident->leg->shipment->shopOwner, 'retry', 'Reauthorize unstarted offer', [], $actor,
                    [...$payload['facts'], 'request_key' => $payload['keys'][1]], null,
                    ['request_key' => $payload['keys'][1], 'expected_revision' => 1, 'findings' => ['obstacle_resolved' => true]]);
            }
        } elseif ($payload['operation'] === 'receipt_module' && $worker === 2) {
            DB::transaction(function () use ($incident) {
                \App\Models\ShopOwner::whereKey($incident->shop_owner_id)->lockForUpdate()->firstOrFail();
                \App\Models\ShopOwnerModule::where('shop_owner_id', $incident->shop_owner_id)->where('module_key', 'logistics')->update(['enabled' => false]);
            });
        } elseif ($payload['operation'] === 'verdict') {
            app(DeliveryIncidentService::class)->resolve($incident, $incident->leg->shipment->shopOwner, 'dismissed', 'Verified obstacle cleared', [], $actor,
                [], null, ['request_key' => $payload['keys'][$worker - 1], 'expected_revision' => 0, 'findings' => ['obstacle_resolved' => true]]);
        } elseif (in_array($payload['operation'], ['receipt', 'receipt_module'], true)) {
            app(ParcelIncidentRecoveryService::class)->receive($incident, $actor, [...$payload['facts'], 'request_key' => $payload['keys'][$worker - 1]]);
        } elseif ($payload['operation'] === 'inspection') {
            app(ParcelIncidentRecoveryService::class)->inspect($incident, $actor, [...$payload['facts'], 'request_key' => $payload['keys'][$worker - 1]]);
        } elseif ($payload['operation'] === 'redelivery') {
            app(ParcelIncidentRecoveryService::class)->disposition($incident, $actor, [...$payload['facts'], 'request_key' => $payload['keys'][$worker - 1]]);
        }
        $result = ['ok' => true, 'connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'held_shop_lock' => $held];
    } catch (\Illuminate\Validation\ValidationException $exception) {
        $result = ['ok' => false, 'errors' => $exception->errors(), 'connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
    }
    file_put_contents($payload['barrier'].'-result-'.$worker.'.json', json_encode($result, JSON_THROW_ON_ERROR));
    exit;
}

$database = 'solespace_parcel_test_'.bin2hex(random_bytes(8));
$directory = base_path('output/parcel-incident-concurrency-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)));
mkdir($directory, 0777, true);
$created = false;
try {
    parcelCheck(! DB::selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]), 'Schema already exists.');
    DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    parcelDatabase($database);
    Artisan::call('migrate', ['--force' => true]);
    $migration = require database_path('migrations/2026_10_27_020923_add_recovery_context_to_delivery_incidents.php');
    $migration->down();
    parcelCheck(! Schema::hasColumn('delivery_incidents', 'recovery_context'), 'Empty context down failed.');
    $migration->up();
    parcelCheck(! Schema::hasColumn('shipment_custody_transfers', 'released_by_shop_owner_id'), 'Deferred Owner field exists.');
    $results = ['engine' => DB::selectOne('SELECT VERSION() AS version')->version, 'schema' => $database, 'migration_empty_down_up' => true];
    foreach (['verdict', 'receipt', 'inspection', 'redelivery', 'activation', 'receipt_module'] as $operation) {
        $fixture = recoveryFixture('retail_delivery', false);
        $leg = ShipmentLeg::with('shipment')->findOrFail($fixture['leg_id']);
        $shop = $leg->shipment->shopOwner;
        $shop->update(['business_type' => 'retail']);
        \App\Models\ShopOwnerModule::updateOrCreate(['shop_owner_id' => $shop->id, 'module_key' => 'retail_operations'], ['enabled' => true]);
        $dispatcher = User::findOrFail($fixture['actors']['dispatcher']['id']);
        $employee = \App\Models\Employee::where('shop_owner_id', $shop->id)->where('email', $dispatcher->email)->firstOrFail();
        DB::table('attendance_records')->insert(['employee_id' => $employee->id, 'shop_owner_id' => $shop->id,
            'date' => now(config('app.shop_timezone'))->toDateString(), 'check_in_time' => '00:00:00', 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
        $incident = app(DeliveryIncidentService::class)->report($leg, $leg->assignments()->latest('id')->first()->riderProfile,
            ['type' => 'vehicle_problem', 'notes' => 'Concurrent synthetic recovery', 'photo_paths' => ['incident-evidence/race.jpg']]);
        $facts = [];
        if ($operation === 'activation') {
            $facts = ['delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'afternoon',
                'rider_profile_id' => $fixture['actors']['a']['rider_id'], 'request_key' => (string) Str::uuid()];
            app(DeliveryIncidentService::class)->resolve($incident, $shop, 'retry', 'Authorize current recovery', [], $dispatcher,
                $facts, null, ['obstacle_resolved' => true]);
            app(\App\Services\Logistics\AssignmentService::class)->respondToOffer($leg->fresh(), $leg->assignments()->latest('id')->firstOrFail()->riderProfile, true);
        } elseif ($operation !== 'verdict') {
            app(DeliveryIncidentService::class)->resolve($incident, $shop, 'return_required', 'Safe return', [], $dispatcher, [], null, ['safe_for_transport' => true]);
            $return = $leg->returnLeg()->firstOrFail();
            $proof = HandoffProof::factory()->create(['shipment_leg_id' => $return->id, 'handoff_type' => 'receive', 'review_status' => 'rider_confirmed',
                'confirmed_by_type' => User::class, 'confirmed_by_id' => $fixture['actors']['a']['id']]);
            $facts = ['expected_revision' => 1, 'proof_id' => $proof->id, 'evidence_path' => 'incident-evidence/receipt.jpg', 'notes' => 'Actual synthetic shop receipt'];
            if (! in_array($operation, ['receipt', 'receipt_module'], true)) app(ParcelIncidentRecoveryService::class)->receive($incident->fresh(), $dispatcher, [...$facts, 'request_key' => (string) Str::uuid()]);
            if (in_array($operation, ['inspection', 'redelivery'], true)) {
                $product = \App\Models\Product::create(['shop_owner_id' => $shop->id, 'name' => 'Original parcel', 'slug' => 'race-'.Str::uuid(), 'price' => 100, 'stock_quantity' => 8, 'is_active' => true]);
                $item = \App\Models\OrderItem::create(['order_id' => $leg->shipment->source_id, 'product_id' => $product->id,
                    'product_name' => $product->name, 'price' => 100, 'quantity' => 1, 'subtotal' => 100]);
                $facts = ['expected_revision' => 2, 'notes' => 'Verified exact original parcel', 'items' => [['reference' => 'order-item:'.$item->id, 'quantity' => 1, 'condition' => 'safe']]];
            }
            if ($operation === 'redelivery') {
                app(ParcelIncidentRecoveryService::class)->inspect($incident->fresh(), $dispatcher, [...$facts, 'request_key' => (string) Str::uuid()]);
                app(ParcelIncidentRecoveryService::class)->disposition($incident->fresh(), $dispatcher, ['request_key' => (string) Str::uuid(), 'expected_revision' => 3,
                    'notes' => 'Vehicle restored and customer arrangement verified', 'action' => 'verify_readiness', 'findings' => ['obstacle_resolved' => true]]);
                $facts = ['expected_revision' => 4, 'notes' => 'Authorized original parcel movement', 'action' => 'redeliver_original',
                    'delivery_date' => now(config('app.shop_timezone'))->toDateString(), 'delivery_window' => 'afternoon', 'rider_profile_id' => $fixture['actors']['b']['rider_id']];
            }
        }
        $barrier = $directory.'/'.$operation;
        $key = (string) Str::uuid();
        $payload = ['database' => $database, 'incident' => $incident->id, 'operation' => $operation, 'barrier' => $barrier,
            'actors' => [$dispatcher->id, $dispatcher->id], 'keys' => [$key, $operation === 'inspection' ? $key : (string) Str::uuid()], 'facts' => $facts];
        file_put_contents($barrier.'.json', json_encode($payload, JSON_THROW_ON_ERROR));
        $processes = [];
        foreach ([1, 2] as $worker) {
            $processes[] = proc_open([PHP_BINARY, __FILE__, '--worker', $barrier.'.json', (string) $worker],
                [0 => ['pipe', 'r'], 1 => ['file', $barrier.'-'.$worker.'.log', 'w'], 2 => ['file', $barrier.'-'.$worker.'.err', 'w']], $pipes, base_path());
            fclose($pipes[0]);
        }
        $exitCodes = array_map(fn ($process) => proc_close($process), $processes);
        parcelCheck($exitCodes === [0, 0], 'Worker failed; inspect '.$barrier.'.');
        $outcomes = array_map(fn ($worker) => json_decode(file_get_contents($barrier.'-result-'.$worker.'.json'), true, 512, JSON_THROW_ON_ERROR), [1, 2]);
        parcelCheck($outcomes[0]['connection'] !== $outcomes[1]['connection'], 'Workers shared a connection.');
        parcelCheck(count(array_filter($outcomes, fn ($row) => $row['ok'])) === (in_array($operation, ['inspection', 'receipt_module'], true) ? 2 : 1), 'Unexpected winner count: '.json_encode($outcomes));
        if (in_array($operation, ['receipt', 'receipt_module'], true)) parcelCheck($proof->fresh()->review_status === 'approved' && $leg->fresh()->custody_state === 'shop', 'Receipt lost custody.');
        if ($operation === 'receipt_module') {
            parcelCheck(! \App\Models\ShopOwnerModule::where('shop_owner_id', $shop->id)->where('module_key', 'logistics')->value('enabled'), 'Module toggle was lost.');
            parcelCheck($leg->custodyTransfers()->count() === 0, 'Receipt created new outbound movement.');
        }
        if ($operation === 'activation') {
            $assignment = $leg->assignments()->latest('id')->firstOrFail();
            parcelCheck($outcomes[0]['ok'] ? ($leg->fresh()->status->value === 'in_transit' && $assignment->status === 'accepted')
                : ($leg->fresh()->status->value === 'needs_resolution' && $assignment->status === 'assigned'), 'Activation and reassignment disagreed.');
        }
        if ($operation === 'inspection') parcelCheck($leg->events()->where('event_type', 'parcel_recovery_inspected')->count() === 1, 'Duplicate inspection event.');
        if ($operation === 'redelivery') {
            parcelCheck(\App\Models\Logistics\Shipment::where('shop_owner_id', $shop->id)->where('source_type', 'order')->where('source_id', $leg->shipment->source_id)->count() === 2, 'Duplicate source attempt.');
            parcelCheck($product->fresh()->stock_quantity === 8, 'Stock allocation changed.');
        }
        $results[$operation] = $outcomes;
    }
    try { $migration->down(); throw new LogicException('Populated context rollback unexpectedly succeeded.'); }
    catch (RuntimeException $exception) { parcelCheck(str_contains($exception->getMessage(), 'Retain incident recovery evidence'), 'Unexpected rollback failure.'); }
    $results['populated_down_guard'] = true;
    file_put_contents($directory.'/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['results' => $directory.'/results.json', 'checks' => array_keys($results)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if ($created) {
        parcelDatabase($database);
        DB::statement('DROP DATABASE `'.$database.'`');
    }
}
