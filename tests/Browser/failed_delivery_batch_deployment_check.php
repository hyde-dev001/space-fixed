<?php

require __DIR__.'/failed_delivery_environment.php';

use App\Models\Logistics\{DeliveryAssignment, DeliveryAttempt, DeliveryBatch, DeliveryIncident, Shipment, ShipmentLeg};
use App\Models\ShopOwner;
use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Symfony\Component\Console\Output\NullOutput;

function batchSchemaCheck(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

// A new schema on the verified disposable server; never delete or reuse earlier evidence.
$database = 'solespace_release_schema_'.date('YmdHis');
DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
batchSchemaCheck(DB::selectOne('SELECT DATABASE() AS name')->name === $database, 'Schema isolation failed.');
batchSchemaCheck(count(DB::select('SHOW TABLES')) === 0, 'Expected an empty disposable schema.');
batchSchemaCheck(Artisan::call('migrate', ['--force' => true], new NullOutput()) === 0, 'Fresh migrations failed.');
$paths = glob($root.'/database/migrations/2026_10_23_*.php');
sort($paths);
batchSchemaCheck(count($paths) === 7, 'Expected all seven approved additive migrations.');
batchSchemaCheck(DB::table('migrations')->orderByDesc('id')->limit(7)->pluck('migration')->reverse()->values()->all()
    === array_map(fn ($path) => basename($path, '.php'), $paths), 'Migration ordering changed.');
$shop = ShopOwner::factory()->approved()->create();
$shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
$leg = ShipmentLeg::factory()->create(['shop_owner_id' => $shop->id, 'shipment_id' => $shipment->id]);
$assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id]);
$attempt = DeliveryAttempt::create(['shipment_leg_id' => $leg->id, 'delivery_assignment_id' => $assignment->id,
    'attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempt_number' => 1, 'attempted_at' => now()]);
$old = DeliveryIncident::factory()->create(['shop_owner_id' => $shop->id, 'shipment_leg_id' => $leg->id]);
$original = DB::table('delivery_attempts')->where('id', $attempt->id)->first();
foreach (array_reverse($paths) as $path) (require $path)->down();
batchSchemaCheck(!Schema::hasTable('delivery_incident_stops') && !Schema::hasColumn('delivery_incidents', 'delivery_batch_id'), 'Empty reversal failed.');
foreach ($paths as $path) (require $path)->up();
batchSchemaCheck(DeliveryIncident::findOrFail($old->id)->shipment_leg_id === $leg->id && $leg->fresh()->custody_state === 'unknown', 'Upgrade changed legacy subject/custody.');
batchSchemaCheck($original == DB::table('delivery_attempts')->where('id', $attempt->id)->first(), 'Attempt history changed.');
$batch = DeliveryBatch::factory()->create(['shop_owner_id' => $shop->id, 'status' => 'interrupted']);
$incident = new DeliveryIncident();
$incident->forceFill(['shop_owner_id' => $shop->id, 'delivery_batch_id' => $batch->id, 'shipment_leg_id' => null,
    'reporting_rider_profile_id' => $old->reporting_rider_profile_id, 'type' => 'vehicle_problem', 'status' => 'reported', 'notes' => 'Synthetic schema check'])->save();
$batch->update(['active_incident_id' => $incident->id]);
$incident->affectedStops()->create(['shipment_leg_id' => $leg->id, 'snapshot' => ['status' => $leg->status->value]]);
$refused = 0;
foreach (array_reverse(array_slice($paths, -2)) as $path) {
    try { (require $path)->down(); }
    catch (RuntimeException $exception) { $refused++; }
}
batchSchemaCheck($refused === 2 && Schema::hasTable('delivery_incident_stops') && $batch->fresh()->active_incident_id === $incident->id, 'Populated rollback did not preserve evidence.');
echo json_encode(['engine' => DB::selectOne('SELECT VERSION() AS version')->version, 'schema' => $database,
    'fresh_migrations' => DB::table('migrations')->count(), 'feature_migrations' => array_map('basename', $paths),
    'all_seven_empty_down_up' => 'passed', 'old_incident_attempt_custody' => 'preserved', 'populated_down_refused' => $refused,
    'rollback_policy' => 'Retain additive schema and evidence in production; the first five down methods are destructive to feature data.',
    'indexes' => DB::select('SHOW INDEX FROM delivery_incident_stops') ? 'present' : 'missing'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
