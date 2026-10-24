<?php

// Creates an empty disposable schema only on the guarded worktree MariaDB instance.
require __DIR__.'/failed_delivery_environment.php';

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\NullOutput;

function deploymentCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = 'solespace_recovery_gap_test';
DB::statement('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
deploymentCheck(DB::selectOne('SELECT DATABASE() AS name')->name === $database, 'Disposable schema selection failed.');
deploymentCheck(count(DB::select('SHOW TABLES')) === 0, 'Use a new empty disposable schema; never overwrite an earlier run.');
$exit = Artisan::call('migrate', ['--force' => true], new NullOutput());
deploymentCheck($exit === 0, 'Fresh MariaDB migrations failed.');
$migrationCount = DB::table('migrations')->count();
$paths = glob($root.'/database/migrations/2026_10_23_*.php');
sort($paths);
deploymentCheck(count($paths) === 5, 'The approved five-migration set changed.');
deploymentCheck(DB::table('migrations')->orderByDesc('id')->limit(5)->pluck('migration')->sort()->values()->all()
    === array_map(fn ($path) => basename($path, '.php'), $paths), 'Feature migration ordering changed.');

$shop = ShopOwner::factory()->approved()->withLogistics()->create(['business_type' => 'both', 'registration_type' => 'company']);
Artisan::call('db:seed', ['--class' => Database\Seeders\RolesAndPermissionsSeeder::class, '--force' => true], new NullOutput());
Artisan::call('db:seed', ['--class' => Database\Seeders\EmployeeSeeder::class, '--force' => true], new NullOutput());
foreach (['Logistics Dispatcher', 'Logistics Rider'] as $role) {
    $user = User::where('shop_owner_id', $shop->id)->whereHas('roles', fn ($q) => $q->where('name', $role))->firstOrFail();
    deploymentCheck($user->role === 'STAFF', 'Invalid legacy role from seeding.');
    deploymentCheck($user->can('resolve-logistics-exceptions') === ($role === 'Logistics Dispatcher'), 'Dispatcher authority leaked.');
    deploymentCheck($user->can('operate-logistics-deliveries') === ($role === 'Logistics Rider'), 'Rider authority leaked.');
}
$shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
$leg = ShipmentLeg::factory()->create(['shop_owner_id' => $shop->id, 'shipment_id' => $shipment->id]);
deploymentCheck($leg->fresh()->custody_state === 'unknown' && !$leg->fresh()->custody_confirmed_at, 'Migration inferred custody.');
$assignment = DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id]);
$attempt = DeliveryAttempt::create(['shipment_leg_id' => $leg->id, 'delivery_assignment_id' => $assignment->id,
    'attempt_type' => 'delivery', 'status' => 'failed', 'reason_code' => 'other', 'attempt_number' => 1, 'attempted_at' => now()]);

foreach (array_reverse($paths) as $path) (require $path)->down();
deploymentCheck(!Schema::hasTable('shipment_custody_transfers'), 'Transfer table was not reversed.');
deploymentCheck(!Schema::hasColumn('shipment_legs', 'custody_state') && !Schema::hasColumn('delivery_assignments', 'recovery_key')
    && !Schema::hasColumn('delivery_events', 'notification_requested_at'), 'Additive columns were not reversed.');
deploymentCheck(DB::table('delivery_attempts')->where('id', $attempt->id)->exists()
    && DB::table('delivery_assignments')->where('id', $assignment->id)->exists()
    && DB::table('shipment_legs')->where('id', $leg->id)->exists(), 'Rollback removed original business history.');
foreach ($paths as $path) (require $path)->up();
deploymentCheck(Schema::hasTable('shipment_custody_transfers') && ShipmentLeg::findOrFail($leg->id)->custody_state === 'unknown', 'Reapply failed or invented custody.');
echo json_encode(['engine' => DB::selectOne('SELECT VERSION() AS version')->version,
    'schema' => $database, 'fresh_migrations' => $migrationCount, 'feature_migrations' => array_map('basename', $paths),
    'logistics_seeders' => 'passed_with_distinct_spatie_permissions', 'rollback_and_reapply' => 'passed',
    'original_attempt_assignment_leg' => 'preserved', 'legacy_custody' => 'unknown',
    'rollback_limit' => 'Feature columns and transfer records would be lost; retain schema for production application rollback.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
