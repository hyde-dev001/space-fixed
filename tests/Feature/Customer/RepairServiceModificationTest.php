<?php

namespace Tests\Feature\Customer;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairServiceModificationTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $customer;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
            'registration_type' => 'company',
        ]);
        $this->customer = User::factory()->create(['status' => 'active']);
        $this->conversation = Conversation::create([
            'shop_owner_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'status' => 'open',
            'priority' => 'medium',
            'last_message_at' => now(),
        ]);
    }

    private function service(
        string $name,
        float $price,
        ?ShopOwner $shop = null,
        string $status = 'Active'
    ): RepairService {
        return RepairService::create([
            'shop_owner_id' => ($shop ?? $this->shop)->id,
            'name' => $name,
            'category' => 'Cleaning',
            'price' => $price,
            'duration' => '1 day',
            'description' => $name,
            'status' => $status,
        ]);
    }

    private function acceptedRepair(array $serviceIds, array $overrides = []): RepairRequest
    {
        $repair = RepairRequest::factory()->create(array_merge([
            'shop_owner_id' => $this->shop->id,
            'user_id' => $this->customer->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'repairer_accepted',
            'payment_status' => 'unpaid',
            'total_paid_amount' => 0,
            'total' => 500,
            'final_total' => 500,
            'pricing_breakdown' => [
                'mode' => 'services',
                'tax_mode' => 'vat_inclusive',
            ],
        ], $overrides));
        $repair->services()->sync($serviceIds);

        return $repair;
    }

    public function test_customer_replaces_services_and_server_reprices_request(): void
    {
        $old = $this->service('Deep Clean', 500);
        $replacement = $this->service('Sole Reglue', 750);
        $repair = $this->acceptedRepair([$old->id], [
            'paymongo_link_id' => 'cs_stale',
            'payment_link_created_at' => now(),
            'payment_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->customer, 'user')->patchJson(
            "/api/customer/repairs/{$repair->id}/services",
            ['service_ids' => [$replacement->id]],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'repairer_accepted')
            ->assertJsonPath('data.total', '750.00')
            ->assertJsonPath('data.services.0.id', $replacement->id);

        $repair->refresh();
        $this->assertSame(
            [$replacement->id],
            $repair->services()->pluck('repair_services.id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertSame('750.00', $repair->total);
        $this->assertSame('750.00', $repair->final_total);
        $this->assertNull($repair->paymongo_link_id);
        $this->assertSame('services', $repair->pricing_breakdown['mode']);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'system',
        ]);

        $message = ConversationMessage::where('conversation_id', $this->conversation->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertStringContainsString('Added: Sole Reglue', $message->content);
        $this->assertStringContainsString('Removed: Deep Clean', $message->content);
        $this->assertStringContainsString('New total: PHP 750.00', $message->content);

        $this->actingAs($this->customer, 'user')
            ->getJson('/api/customer/repairs')
            ->assertOk()
            ->assertJsonPath('data.0.services.0.id', $replacement->id);
    }

    public function test_customer_can_remove_package_and_convert_to_individual_services(): void
    {
        $included = $this->service('Package Clean', 500);
        $replacement = $this->service('Custom Repair', 900);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Cleaning Package',
            'package_price' => 450,
            'status' => 'active',
        ]);
        $package->services()->sync([$included->id]);
        $repair = $this->acceptedRepair([$included->id], [
            'repair_package_id' => $package->id,
            'package_price' => 450,
        ]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$replacement->id],
                'remove_package' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.repair_package_id', null);

        $repair->refresh();
        $this->assertNull($repair->repair_package_id);
        $this->assertNull($repair->package_price);
        $this->assertSame('0.00', $repair->add_ons_total);
        $this->assertSame(
            [$replacement->id],
            collect($repair->included_services_snapshot)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }

    public function test_package_requires_explicit_removal_before_services_change(): void
    {
        $service = $this->service('Package Clean', 500);
        $package = RepairPackage::create([
            'shop_owner_id' => $this->shop->id,
            'name' => 'Cleaning Package',
            'package_price' => 450,
            'status' => 'active',
        ]);
        $repair = $this->acceptedRepair([$service->id], [
            'repair_package_id' => $package->id,
        ]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$service->id],
                'remove_package' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remove_package');
    }

    public function test_services_must_be_active_and_belong_to_the_same_shop(): void
    {
        $current = $this->service('Deep Clean', 500);
        $otherShop = ShopOwner::factory()->approved()->create();
        $wrongShop = $this->service('Foreign Service', 100, $otherShop);
        $inactive = $this->service('Inactive Service', 100, null, 'Inactive');
        $repair = $this->acceptedRepair([$current->id]);

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$wrongShop->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids');

        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$inactive->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_ids');
    }

    public function test_only_owner_can_modify_an_accepted_unpaid_repair_with_chat(): void
    {
        $service = $this->service('Deep Clean', 500);
        $repair = $this->acceptedRepair([$service->id]);
        $otherCustomer = User::factory()->create(['status' => 'active']);

        $this->actingAs($otherCustomer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$service->id],
            ])
            ->assertNotFound();

        $repair->update(['status' => 'pending']);
        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$service->id],
            ])
            ->assertStatus(409);

        $repair->update([
            'status' => 'repairer_accepted',
            'payment_status' => 'paid',
            'total_paid_amount' => 500,
        ]);
        $this->actingAs($this->customer, 'user')
            ->patchJson("/api/customer/repairs/{$repair->id}/services", [
                'service_ids' => [$service->id],
            ])
            ->assertStatus(409);
    }
}
