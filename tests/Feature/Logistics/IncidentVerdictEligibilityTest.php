<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\LogisticsSetting;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidentVerdictEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public static function reportCases(): array
    {
        return [
            'PD-01 unsafe continue' => ['damaged', 'dismissed', false],
            'PD-02 unsafe retry' => ['damaged', 'retry', false],
            'PD-03 safe return decision' => ['damaged', 'return_required', true],
            'PL-01 missing continue' => ['lost', 'dismissed', false],
            'PL-02 missing retry' => ['lost', 'retry', false],
            'PL-03 missing return' => ['lost', 'return_required', false],
            'PL-04 investigated loss decision' => ['lost', 'loss_confirmed', true],
            'VR-01 unverified obstacle' => ['vehicle_problem', 'dismissed', false],
            'VR-02 retry decision' => ['vehicle_problem', 'retry', true],
            'VR-03 shop retry decision' => ['vehicle_problem', 'retry', true],
            'VR-04 safe return decision' => ['vehicle_problem', 'return_required', true],
            'CD-01 unresolved continue' => ['customer_dispute', 'dismissed', false],
            'CD-02 unresolved retry' => ['customer_dispute', 'retry', false],
            'CD-03 unresolved shop retry' => ['customer_dispute', 'retry', false],
            'CD-04 safekeeping return decision' => ['customer_dispute', 'return_required', true],
        ];
    }

    #[DataProvider('reportCases')]
    public function test_report_case_decision_does_not_bypass_condition_or_close_recovery(string $type, string $verdict, bool $allowed): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(9, 0));
        $shop = ShopOwner::factory()->approved()->withLogistics()->create(['registration_type' => 'company']);
        LogisticsSetting::updateOrCreate(['shop_owner_id' => $shop->id], ['operating_days' => range(1, 7)]);
        $rider = RiderProfile::factory()->create(['shop_owner_id' => $shop->id, 'work_days' => range(1, 7)]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => Shipment::factory()->create(['shop_owner_id' => $shop->id])->id,
            'status' => 'in_transit', 'custody_state' => $type === 'lost' ? 'lost' : 'rider',
            'custody_rider_profile_id' => $type === 'lost' ? null : $rider->id, 'custody_confirmed_at' => now(),
        ]);
        DeliveryAssignment::factory()->create(['shipment_leg_id' => $leg->id, 'rider_profile_id' => $rider->id, 'status' => 'accepted']);
        $service = app(DeliveryIncidentService::class);
        $incident = $service->report($leg, $rider, ['type' => $type, 'notes' => 'Actual incident report', 'photo_paths' => ['incident-evidence/test/report.jpg']]);
        $findings = ['safe_for_transport' => true, 'obstacle_resolved' => $allowed];

        if (! $allowed) {
            try {
                $service->resolve($incident, $shop, $verdict, 'Investigation note alone is insufficient', ['incident-evidence/test/finding.jpg'], null, [], null, $findings);
                $this->fail('An unverified or unsafe decision was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('eligibility', $exception->errors());
            }
            $this->assertSame('reported', $incident->fresh()->status);
            $this->assertNull($incident->fresh()->resolution);
            $this->assertSame(1, $leg->assignments()->count());
            $this->assertFalse($leg->returnLeg()->exists());
            return;
        }

        $decided = $service->resolve($incident, $shop, $verdict, 'Verified operational decision', ['incident-evidence/test/finding.jpg'], null, [], null, $findings);
        $this->assertSame('under_review', $decided->status);
        $this->assertNull($decided->resolved_at);
        $this->assertNotNull($decided->decided_at);
        $this->assertSame('Actual incident report', $decided->notes);
        $this->assertSame($verdict, $decided->resolution);
    }
}
