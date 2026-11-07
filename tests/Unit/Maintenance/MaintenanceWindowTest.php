<?php

namespace Tests\Unit\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceWindow;
use App\Models\SuperAdmin;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_window_casts_its_canonical_fields(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $window = MaintenanceWindow::factory()->scheduled()->create([
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->assertSame(MaintenanceStatus::Scheduled, $window->status);
        $this->assertInstanceOf(CarbonInterface::class, $window->starts_at);
        $this->assertInstanceOf(CarbonInterface::class, $window->ends_at);
        $this->assertIsInt($window->version);
        $this->assertIsInt($window->notify_before_minutes);
        $this->assertIsInt($window->transaction_freeze_minutes);
        $this->assertInstanceOf(SuperAdmin::class, $window->creator);
        $this->assertInstanceOf(SuperAdmin::class, $window->updater);
        $this->assertFalse(Schema::hasColumn('maintenance_windows', 'deleted_at'));
    }

    public function test_terminal_statuses_are_available_as_factory_states(): void
    {
        $this->assertSame(MaintenanceStatus::Draft, MaintenanceWindow::factory()->draft()->make()->status);
        $this->assertSame(MaintenanceStatus::Scheduled, MaintenanceWindow::factory()->scheduled()->make()->status);
        $this->assertSame(MaintenanceStatus::Active, MaintenanceWindow::factory()->active()->make()->status);
        $this->assertSame(MaintenanceStatus::Ended, MaintenanceWindow::factory()->ended()->make()->status);
        $this->assertSame(MaintenanceStatus::Cancelled, MaintenanceWindow::factory()->cancelled()->make()->status);
    }
}
