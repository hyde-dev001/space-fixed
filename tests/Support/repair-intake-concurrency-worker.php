<?php

use App\Models\RepairRequest;
use App\Services\RepairDeliveryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $databasePath, $repairId, $barrierDirectory, $workerId, $resultPath] = $argv;
$connection = config('database.connections.sqlite');
$connection['database'] = $databasePath;
$connection['busy_timeout'] = 5000;
$connection['journal_mode'] = 'WAL';
$connection['synchronous'] = 'NORMAL';

config([
    'database.default' => 'repair_concurrency',
    'database.connections.repair_concurrency' => $connection,
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'mail.default' => 'array',
]);
DB::purge('repair_concurrency');

$barrierReached = false;
DB::connection()->listen(function (QueryExecuted $query) use (
    &$barrierReached,
    $barrierDirectory,
    $workerId,
): void {
    if ($barrierReached
        || ! str_contains(strtolower($query->sql), 'shipments')
        || ! in_array('repair_pickup', $query->bindings, true)) {
        return;
    }

    $barrierReached = true;
    file_put_contents($barrierDirectory.DIRECTORY_SEPARATOR."ready-{$workerId}", 'ready');

    $deadline = microtime(true) + 10;
    while (count(glob($barrierDirectory.DIRECTORY_SEPARATOR.'ready-*')) < 2) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the competing intake shipment query.');
        }

        usleep(10_000);
    }
});

try {
    $repair = RepairRequest::query()->findOrFail((int) $repairId);
    $shipment = app(RepairDeliveryService::class)->tryCreateIntakeShipment($repair);

    if ($shipment === null) {
        throw new RuntimeException('The ready repair did not create or return an intake shipment.');
    }

    file_put_contents($resultPath, json_encode(['shipment_id' => $shipment->id], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);

    exit(1);
}
