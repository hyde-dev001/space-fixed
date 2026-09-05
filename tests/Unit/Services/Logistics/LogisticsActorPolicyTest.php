<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logistics;

use App\Enums\Logistics\LogisticsAction;
use App\Models\Logistics\DeliveryAssignment;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\ShopOwnerModule;
use App\Models\User;
use App\Services\Logistics\LogisticsActorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class LogisticsActorPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_matrix_keeps_actor_responsibility_explicit(): void
    {
        $shop = $this->shopWithLogistics();
        $owner = $shop;
        $dispatcher = $this->user($shop);
        $reviewer = $this->user($shop);
        $rider = $this->user($shop);
        $unassignedRider = $this->user($shop);
        $leg = $this->leg($shop, 'pending');
        $proofLeg = $this->leg($shop, 'awaiting_proof_approval');

        $this->grant($dispatcher, 'assign-logistics-deliveries');
        $this->grant($reviewer, 'approve-proof-of-delivery');
        $this->grant($rider, 'record-logistics-proof');
        $this->grant($unassignedRider, 'record-logistics-proof');

        $riderProfile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'employee',
            'linked_type' => User::class,
            'linked_id' => $rider->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $riderProfile->id,
            'status' => 'assigned',
        ]);

        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $proofLeg->id,
            'review_status' => 'pending',
            'confirmed_by_type' => User::class,
            'confirmed_by_id' => $rider->id,
        ]);

        $policy = app(LogisticsActorPolicy::class);

        $cases = [
            'owner dispatches' => [
                $owner,
                LogisticsAction::ASSIGN_RIDER,
                $leg,
                null,
                true,
            ],
            'employee dispatcher dispatches' => [
                $dispatcher,
                LogisticsAction::ASSIGN_RIDER,
                $leg,
                null,
                true,
            ],
            'employee reviewer reviews' => [
                $reviewer,
                LogisticsAction::REVIEW_PROOF,
                $proofLeg,
                $proof,
                true,
            ],
            'assigned rider submits custody proof' => [
                $rider,
                LogisticsAction::SUBMIT_PROOF,
                $leg,
                null,
                true,
            ],
            'unassigned rider cannot submit custody proof' => [
                $unassignedRider,
                LogisticsAction::SUBMIT_PROOF,
                $leg,
                null,
                false,
            ],
        ];

        foreach ($cases as $label => [$actor, $action, $caseLeg, $caseProof, $expected]) {
            $decision = $policy->decide($actor, $action, $shop, $caseLeg, $caseProof);

            self::assertSame($expected, $decision['allowed'], $label);
            self::assertSame($action->value, $decision['action'], $label);
            self::assertTrue($decision['reason_category'] === null || preg_match('/^[a-z_]+$/', $decision['reason_category']) === 1, $label);
        }
    }

    public function test_owner_guard_does_not_grant_custody_without_a_linked_owner_rider(): void
    {
        $shop = $this->shopWithLogistics();
        $leg = $this->leg($shop, 'in_transit');

        $decision = app(LogisticsActorPolicy::class)->decide(
            $shop,
            LogisticsAction::SUBMIT_PROOF,
            $shop,
            $leg,
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('rider_identity_required', $decision['reason_category']);
    }

    public function test_linked_owner_rider_may_submit_proof_only_for_the_exact_active_assignment(): void
    {
        $shop = $this->shopWithLogistics();
        $leg = $this->leg($shop, 'in_transit');
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'rider_type' => 'shop_owner',
            'linked_type' => ShopOwner::class,
            'linked_id' => $shop->id,
            'active' => true,
            'availability_status' => 'available',
        ]);
        DeliveryAssignment::factory()->create([
            'shipment_leg_id' => $leg->id,
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
        ]);

        $decision = app(LogisticsActorPolicy::class)->decide(
            $shop,
            LogisticsAction::SUBMIT_PROOF,
            $shop,
            $leg,
        );

        self::assertTrue($decision['allowed']);
        self::assertNull($decision['reason_category']);
    }

    public function test_owner_dispatch_and_review_require_an_enabled_module_and_valid_source_state(): void
    {
        $shop = $this->shopWithLogistics();
        $leg = $this->leg($shop, 'delivered');
        $policy = app(LogisticsActorPolicy::class);

        $invalidState = $policy->decide($shop, LogisticsAction::ASSIGN_RIDER, $shop, $leg);

        self::assertFalse($invalidState['allowed']);
        self::assertSame('source_state_invalid', $invalidState['reason_category']);

        $shop->modules()->updateOrCreate(['module_key' => 'logistics'], ['enabled' => false]);
        $moduleDisabled = $policy->decide($shop, LogisticsAction::ASSIGN_RIDER, $shop, $this->leg($shop, 'pending'));

        self::assertFalse($moduleDisabled['allowed']);
        self::assertSame('module_unavailable', $moduleDisabled['reason_category']);
    }

    public function test_batch_management_has_an_explicit_owner_and_employee_boundary(): void
    {
        $shop = $this->shopWithLogistics();
        $dispatcher = $this->user($shop);
        $unprivileged = $this->user($shop);
        $policy = app(LogisticsActorPolicy::class);

        self::assertTrue($policy->decideBatchManagement($shop, $shop)['allowed']);

        $denied = $policy->decideBatchManagement($unprivileged, $shop);
        self::assertFalse($denied['allowed']);
        self::assertSame('action_not_allowed', $denied['reason_category']);

        $this->grant($dispatcher, 'manage-logistics-batches');
        self::assertTrue($policy->decideBatchManagement($dispatcher, $shop)['allowed']);

        $shop->modules()->updateOrCreate(['module_key' => 'logistics'], ['enabled' => false]);
        $disabled = $policy->decideBatchManagement($shop, $shop);
        self::assertFalse($disabled['allowed']);
        self::assertSame('module_unavailable', $disabled['reason_category']);
    }

    public function test_dispatch_capability_can_resolve_a_failed_repair_pickup(): void
    {
        $shop = $this->shopWithLogistics();
        $dispatcher = $this->user($shop);
        $this->grant($dispatcher, 'assign-logistics-deliveries');
        $shipment = Shipment::factory()->create([
            'shop_owner_id' => $shop->id,
            'source_type' => 'repair_request',
            'purpose' => 'repair_pickup',
        ]);
        $leg = ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => 'needs_resolution',
            'resolution_type' => 'pickup_failed',
        ]);

        $decision = app(LogisticsActorPolicy::class)->decide(
            $dispatcher,
            LogisticsAction::RESOLVE_EXCEPTION,
            $shop,
            $leg,
        );

        self::assertTrue($decision['allowed']);
        self::assertNull($decision['reason_category']);
    }

    public function test_repair_pickup_cancellation_replay_requires_explicit_opt_in(): void
    {
        $shop = $this->shopWithLogistics();
        $leg = $this->leg($shop, 'cancelled');
        $leg->update(['resolution_type' => 'pickup_failed']);
        $leg->shipment->update([
            'source_type' => 'repair_request',
            'purpose' => 'repair_pickup',
        ]);
        $policy = app(LogisticsActorPolicy::class);

        $denied = $policy->decide($shop, LogisticsAction::RESOLVE_EXCEPTION, $shop, $leg);
        self::assertFalse($denied['allowed']);
        self::assertSame('source_state_invalid', $denied['reason_category']);

        $replay = $policy->decide(
            $shop,
            LogisticsAction::RESOLVE_EXCEPTION,
            $shop,
            $leg,
            null,
            true,
        );
        self::assertTrue($replay['allowed']);
    }

    public function test_cross_shop_records_fail_with_a_generic_category(): void
    {
        $shop = $this->shopWithLogistics();
        $otherShop = $this->shopWithLogistics();
        $otherLeg = $this->leg($otherShop, 'pending');

        $decision = app(LogisticsActorPolicy::class)->decide(
            $shop,
            LogisticsAction::ASSIGN_RIDER,
            $shop,
            $otherLeg,
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('cross_shop', $decision['reason_category']);
    }

    public function test_proof_submitter_cannot_automatically_review_the_same_proof(): void
    {
        $shop = $this->shopWithLogistics();
        $reviewer = $this->user($shop);
        $this->grant($reviewer, 'approve-proof-of-delivery');
        $leg = $this->leg($shop, 'awaiting_proof_approval');
        $proof = HandoffProof::factory()->create([
            'shipment_leg_id' => $leg->id,
            'review_status' => 'pending',
            'confirmed_by_type' => User::class,
            'confirmed_by_id' => $reviewer->id,
        ]);

        $decision = app(LogisticsActorPolicy::class)->decide(
            $reviewer,
            LogisticsAction::REVIEW_PROOF,
            $shop,
            $leg,
            $proof,
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('maker_checker_conflict', $decision['reason_category']);
    }

    private function shopWithLogistics(): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);

        ShopOwnerModule::factory()->create([
            'shop_owner_id' => $shop->id,
            'module_key' => 'logistics',
            'enabled' => true,
        ]);

        return $shop;
    }

    private function user(ShopOwner $shop): User
    {
        return User::factory()->create(['shop_owner_id' => $shop->id]);
    }

    private function grant(User $user, string $permission): void
    {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'user'));
    }

    private function leg(ShopOwner $shop, string $status): ShipmentLeg
    {
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);

        return ShipmentLeg::factory()->create([
            'shipment_id' => $shipment->id,
            'status' => $status,
        ]);
    }
}
