<?php

namespace Tests\Feature\Repair\Warranty;

use App\Models\PosReceipt;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\RepairWarrantyClaim;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepairWarrantyEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_customer_can_file_warranty_claim_within_window(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
            'received_at' => now()->subDays(3),
            'payment_status' => 'completed',
            'total_paid_amount' => 900,
        ]);

        $response = $this->actingAs($customer, 'user')->post(
            "/api/customer/repairs/{$repair->id}/warranty-claims",
            [
                'reason_code' => 'issue_returned',
                'reason_details' => 'Issue came back after two days.',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('proof-1.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', RepairWarrantyClaim::STATUS_PENDING_REPAIRER);

        $this->assertDatabaseHas('repair_warranty_claims', [
            'original_repair_request_id' => $repair->id,
            'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'source_channel' => 'customer_portal',
        ]);
    }

    public function test_customer_claim_fails_when_warranty_is_expired(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 7,
        ]);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(10),
            'payment_status' => 'completed',
        ]);

        $response = $this->actingAs($customer, 'user')->post(
            "/api/customer/repairs/{$repair->id}/warranty-claims",
            [
                'reason_code' => 'issue_returned',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('expired.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['repair']);
    }

    public function test_customer_claim_fails_when_approved_claim_already_exists(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
        ]);

        RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-TEST-0001',
            'original_repair_request_id' => $repair->id,
            'customer_user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => RepairWarrantyClaim::STATUS_APPROVED,
            'reason_code' => 'issue_returned',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/old-proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'customer_portal',
            'warranty_started_at_snapshot' => now()->subDays(2),
            'warranty_expires_at_snapshot' => now()->addDays(20),
            'approved_once_guard' => 1,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'user')->post(
            "/api/customer/repairs/{$repair->id}/warranty-claims",
            [
                'reason_code' => 'issue_returned',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('duplicate.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['repair']);
    }

    public function test_customer_can_refile_after_rejected_claim_within_window(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
            'payment_status' => 'completed',
        ]);

        RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-TEST-0002',
            'original_repair_request_id' => $repair->id,
            'customer_user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => RepairWarrantyClaim::STATUS_REJECTED,
            'reason_code' => 'issue_returned',
            'reason_details' => 'Previous claim was rejected.',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/rejected-proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'customer_portal',
            'warranty_started_at_snapshot' => now()->subDays(2),
            'warranty_expires_at_snapshot' => now()->addDays(20),
            'reviewed_at' => now()->subHours(4),
            'rejection_reason' => 'Need clearer evidence.',
        ]);

        $response = $this->actingAs($customer, 'user')->post(
            "/api/customer/repairs/{$repair->id}/warranty-claims",
            [
                'reason_code' => 'issue_returned',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('refile.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', RepairWarrantyClaim::STATUS_PENDING_REPAIRER);
    }

    public function test_customer_claim_fails_when_pending_claim_already_exists(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);
        /** @var User $customer */
        $customer = User::factory()->create();

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(2),
            'payment_status' => 'completed',
        ]);

        RepairWarrantyClaim::query()->create([
            'claim_no' => 'WCLM-TEST-0003',
            'original_repair_request_id' => $repair->id,
            'customer_user_id' => $customer->id,
            'shop_owner_id' => $shopOwner->id,
            'status' => RepairWarrantyClaim::STATUS_PENDING_REPAIRER,
            'reason_code' => 'issue_returned',
            'reason_details' => 'Initial claim pending review.',
            'same_issue_confirmation' => true,
            'evidence_media' => ['repair-warranty-claims/pending-proof.jpg'],
            'preferred_return_method' => 'walk_in',
            'shipping_cost_bearer' => 'customer',
            'source_channel' => 'customer_portal',
            'warranty_started_at_snapshot' => now()->subDays(2),
            'warranty_expires_at_snapshot' => now()->addDays(20),
        ]);

        $response = $this->actingAs($customer, 'user')->post(
            "/api/customer/repairs/{$repair->id}/warranty-claims",
            [
                'reason_code' => 'issue_returned',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('duplicate-pending.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['repair']);
    }

    public function test_manual_pos_walk_in_claim_requires_matching_receipt_and_phone(): void
    {
        Storage::fake('public');

        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
            'warranty_enabled' => true,
            'repair_warranty_days' => 30,
        ]);

        /** @var User $staff */
        $staff = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
        ]);

        $repair = RepairRequest::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'user_id' => null,
            'request_id' => 'REP-POS-20260412-0001',
            'status' => 'picked_up',
            'picked_up_at' => now()->subDays(1),
            'phone' => '09171234567',
            'pricing_breakdown' => ['mode' => 'manual_pos'],
        ]);

        $transaction = PosTransaction::query()->create([
            'transaction_no' => 'POS-TEST-0001',
            'shop_owner_id' => $shopOwner->id,
            'module_type' => 'repair',
            'module_reference_id' => $repair->id,
            'customer_type' => 'walk_in',
            'walk_in_name' => 'Walk In Customer',
            'walk_in_phone' => '09171234567',
            'due_type' => 'full',
            'subtotal' => 100,
            'tax_amount' => 12,
            'discount_amount' => 0,
            'total_amount' => 112,
            'paid_amount' => 112,
            'status' => 'paid',
            'paid_at' => now(),
            'created_by' => $staff->id,
        ]);

        PosReceipt::query()->create([
            'pos_transaction_id' => $transaction->id,
            'receipt_no' => 'RCP-TEST-0001',
            'issued_at' => now(),
            'print_payload' => ['sample' => true],
            'digital_payload' => ['sample' => true],
        ]);

        $success = $this->actingAs($staff, 'user')->post(
            '/api/repair-pos/warranty-claims',
            [
                'repair_request_id' => $repair->id,
                'receipt_no' => 'RCP-TEST-0001',
                'walk_in_phone' => '09171234567',
                'reason_code' => 'issue_returned',
                'reason_details' => 'Stitch issue returned after pickup.',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('walkin-proof.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $success->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source_channel', 'manual_pos_walk_in');

        $mismatch = $this->actingAs($staff, 'user')->post(
            '/api/repair-pos/warranty-claims',
            [
                'repair_request_id' => $repair->id,
                'receipt_no' => 'RCP-TEST-0001',
                'walk_in_phone' => '09998887777',
                'reason_code' => 'issue_returned',
                'same_issue_confirmation' => '1',
                'preferred_return_method' => 'walk_in',
                'images' => [UploadedFile::fake()->create('walkin-proof-2.jpg', 64, 'image/jpeg')],
            ],
            ['Accept' => 'application/json']
        );

        $mismatch->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
