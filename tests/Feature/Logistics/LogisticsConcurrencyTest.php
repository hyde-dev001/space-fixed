<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Logistics\ShipmentLegService;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class LogisticsConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_return_creation_converges_on_one_leg(): void
    {
        $shipment = Shipment::factory()->create();
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shipment->shop_owner_id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'needs_resolution', 'resolution_type' => 'return_required']);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(ShipmentLegService::class);

        $first = $service->createReturnToShop($leg);
        $second = $service->createReturnToShop($leg->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShipmentLeg::where('return_for_leg_id', $leg->id)->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_concurrent_repair_intake_requests_converge_on_one_shipment(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solespace-repair-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $databasePath = $directory.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($databasePath);

        $originalConnection = config('database.default');
        $connection = config('database.connections.sqlite');
        $connection['database'] = $databasePath;
        $connection['busy_timeout'] = 5000;
        $connection['journal_mode'] = 'WAL';
        $connection['synchronous'] = 'NORMAL';
        config([
            'database.default' => 'repair_concurrency',
            'database.connections.repair_concurrency' => $connection,
        ]);

        try {
            Artisan::call('migrate:fresh', ['--database' => 'repair_concurrency', '--force' => true]);
            $repair = $this->readyCoveredRepair();
            $processes = [];

            foreach ([1, 2] as $workerId) {
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$workerId}.json";
                $stdoutPath = $directory.DIRECTORY_SEPARATOR."stdout-{$workerId}.log";
                $stderrPath = $directory.DIRECTORY_SEPARATOR."stderr-{$workerId}.log";
                $process = proc_open([
                    PHP_BINARY,
                    base_path('tests/Support/repair-intake-concurrency-worker.php'),
                    $databasePath,
                    (string) $repair->id,
                    $directory,
                    (string) $workerId,
                    $resultPath,
                ], [
                    0 => ['pipe', 'r'],
                    1 => ['file', $stdoutPath, 'a'],
                    2 => ['file', $stderrPath, 'a'],
                ], $pipes, base_path());

                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = compact('process', 'resultPath', 'stderrPath');
            }

            $shipmentIds = [];
            foreach ($processes as $worker) {
                $exitCode = $this->waitForProcess($worker['process']);
                $result = is_file($worker['resultPath'])
                    ? json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR)
                    : [];
                $stderr = is_file($worker['stderrPath']) ? trim(file_get_contents($worker['stderrPath'])) : '';

                $this->assertSame(0, $exitCode, $stderr ?: json_encode($result));
                $shipmentIds[] = (int) ($result['shipment_id'] ?? 0);
            }

            $this->assertGreaterThan(0, $shipmentIds[0]);
            $this->assertSame($shipmentIds[0], $shipmentIds[1]);
            $this->assertSame(1, Shipment::query()
                ->where('source_type', 'repair_request')
                ->where('source_id', $repair->id)
                ->where('purpose', 'repair_pickup')
                ->count());
        } finally {
            config(['database.default' => $originalConnection]);
            DB::purge('repair_concurrency');

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function readyCoveredRepair(): RepairRequest
    {
        $shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'shop_latitude' => 14.5995,
            'shop_longitude' => 120.9842,
        ]);
        LogisticsSetting::create([
            'shop_owner_id' => $shop->id,
            'coverage_radius_km' => 12,
            'lead_time_days' => 0,
        ]);
        RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        $customer = User::factory()->create();
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => '1 Test Street',
            'latitude' => 14.6,
            'longitude' => 120.98,
        ]);
        $delivery = app(RepairDeliveryService::class);
        $quote = $delivery->quote($shop, $address);

        return RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'status' => 'repairer_accepted',
            'delivery_method' => 'pickup',
            'intake_delivery_method' => 'shop_pickup',
            'intake_address' => $delivery->snapshot($address, 'shop_pickup'),
            'intake_delivery_fee' => $quote['fee'],
            'intake_logistics_quote' => $quote,
            'payment_status' => 'paid',
            'total_paid_amount' => 500 + (float) $quote['fee'],
            'intake_logistics_locked_at' => now(),
        ]);
    }

    /** @param resource $process */
    private function waitForProcess($process): int
    {
        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                $closedExitCode = proc_close($process);

                return $exitCode >= 0 ? $exitCode : $closedExitCode;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        $this->fail('Timed out waiting for the concurrent repair intake worker.');
    }
}
