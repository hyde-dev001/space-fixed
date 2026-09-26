<?php

namespace Database\Factories\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryIncidentFactory extends Factory
{
    protected $model = DeliveryIncident::class;
    public function definition(): array { return ['shop_owner_id' => fn () => ShipmentLeg::factory()->create()->shipment->shop_owner_id, 'shipment_leg_id' => ShipmentLeg::factory(), 'reporting_rider_profile_id' => RiderProfile::factory(), 'type' => 'damaged', 'notes' => 'Parcel damaged']; }
}
