<?php

namespace Tests\Feature\Repair;

use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\RepairDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepairSponsoredDeliveryPlanReplayTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('sponsoredPlanCases')]
    public function test_identical_sponsored_locked_plan_replay_is_a_no_op(
        string $leg,
        array $warrantyMarker,
    ): void {
        [$repair, $customer, $address, $otherAddress] = $this->lockedPlanFixture($leg, $warrantyMarker);
        $lockField = "{$leg}_logistics_locked_at";
        $addressField = "{$leg}_address";
        $lockBefore = $repair->{$lockField}->copy();
        $updatedBefore = $repair->updated_at->copy();
        $versionBefore = data_get($repair->{$addressField}, 'version');

        $this->actingAs($customer, 'user')
            ->patchJson(
                "/api/customer/repairs/{$repair->id}/delivery-method",
                $this->planPayload($leg, $address->id),
            )
            ->assertOk()
            ->assertJsonPath("{$addressField}.address_id", $address->id);

        $repair->refresh();
        $this->assertTrue($lockBefore->equalTo($repair->{$lockField}));
        $this->assertTrue($updatedBefore->equalTo($repair->updated_at));
        $this->assertSame($versionBefore, data_get($repair->{$addressField}, 'version'));

        $this->actingAs($customer, 'user')
            ->patchJson(
                "/api/customer/repairs/{$repair->id}/delivery-method",
                $this->planPayload($leg, $otherAddress->id),
            )
            ->assertUnprocessable();

        $this->assertSame($versionBefore, data_get($repair->fresh()->{$addressField}, 'version'));
    }

    #[DataProvider('lockedLegs')]
    public function test_identical_ordinary_paid_locked_plan_remains_rejected(string $leg): void
    {
        [$repair, $customer, $address] = $this->lockedPlanFixture($leg, [
            'is_warranty_job' => false,
            'billing_mode' => null,
        ]);

        $this->actingAs($customer, 'user')
            ->patchJson(
                "/api/customer/repairs/{$repair->id}/delivery-method",
                $this->planPayload($leg, $address->id),
            )
            ->assertUnprocessable();
    }

    public static function sponsoredPlanCases(): array
    {
        return [
            'intake warranty job marker' => ['intake', [
                'is_warranty_job' => true,
                'billing_mode' => 'warranty',
            ]],
            'intake no-charge billing marker' => ['intake', [
                'is_warranty_job' => false,
                'billing_mode' => 'warranty_no_charge',
            ]],
            'return warranty job marker' => ['return', [
                'is_warranty_job' => true,
                'billing_mode' => 'warranty',
            ]],
            'return no-charge billing marker' => ['return', [
                'is_warranty_job' => false,
                'billing_mode' => 'warranty_no_charge',
            ]],
        ];
    }

    public static function lockedLegs(): array
    {
        return [
            'intake' => ['intake'],
            'return' => ['return'],
        ];
    }

    private function lockedPlanFixture(string $leg, array $warrantyMarker): array
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'repair']);
        $customer = User::factory()->create();
        $address = $this->address($customer, '1 Retry Street');
        $otherAddress = $this->address($customer, '2 Different Street');
        $method = $leg === 'intake' ? 'customer_delivery' : 'customer_pickup';
        $snapshot = app(RepairDeliveryService::class)->snapshot($address, $method);
        $lockedAt = now()->subHour()->startOfSecond();
        $updatedAt = now()->subDay()->startOfSecond();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shop->id,
            'user_id' => $customer->id,
            'status' => 'repairer_accepted',
            'payment_status' => 'completed',
            'delivery_method' => $leg === 'intake' ? 'pickup' : 'walk_in',
            'intake_delivery_method' => $leg === 'intake' ? $method : 'walk_in',
            'intake_address' => $leg === 'intake' ? $snapshot : null,
            'return_delivery_method' => $leg === 'return' ? $method : 'walk_in',
            'return_address' => $leg === 'return' ? $snapshot : null,
            'same_as_intake_address' => false,
        ]);
        $repair->forceFill([
            ...$warrantyMarker,
            "{$leg}_logistics_locked_at" => $lockedAt,
            'updated_at' => $updatedAt,
        ])->save();

        return [$repair->fresh(), $customer, $address, $otherAddress];
    }

    private function planPayload(string $leg, int $addressId): array
    {
        return $leg === 'intake'
            ? [
                'intake_delivery_method' => 'customer_delivery',
                'intake_address_id' => $addressId,
            ]
            : [
                'return_delivery_method' => 'customer_pickup',
                'return_address_id' => $addressId,
                'same_as_intake_address' => false,
            ];
    }

    private function address(User $customer, string $addressLine): UserAddress
    {
        return UserAddress::create([
            'user_id' => $customer->id,
            'name' => $customer->name,
            'phone' => '09171234567',
            'region' => 'NCR',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'address_line' => $addressLine,
            'postal_code' => '1000',
            'latitude' => 14.6,
            'longitude' => 120.98,
            'delivery_instructions' => 'Blue gate',
        ]);
    }
}
