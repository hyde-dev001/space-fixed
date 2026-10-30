<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ParcelIncidentRecoveryPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_requires_shop_and_never_fabricates_historical_confirmation(): void
    {
        $leg = ShipmentLeg::factory()->create();
        $incident = DeliveryIncident::factory()->create(['shop_owner_id' => $leg->shipment->shop_owner_id,
            'shipment_leg_id' => $leg->id, 'type' => 'lost', 'status' => 'reported', 'recovery_context' => null]);
        $before = $incident->fresh()->getRawOriginal();
        $this->assertSame(2, Artisan::call('logistics:preview-parcel-recovery'));
        $this->assertSame(0, Artisan::call('logistics:preview-parcel-recovery', ['--shop' => $incident->shop_owner_id]));
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('preview_only', $rows['mode']);
        $this->assertContains('legacy_report_needs_actual_dispatcher_acknowledgement', $rows['records'][0]['issues']);
        $this->assertSame($before, $incident->fresh()->getRawOriginal());
        Artisan::call('logistics:preview-parcel-recovery', ['--shop' => $incident->shop_owner_id + 1000]);
        $this->assertSame([], json_decode(Artisan::output(), true)['records']);
    }
}
