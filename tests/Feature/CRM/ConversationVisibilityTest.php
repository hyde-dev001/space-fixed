<?php

namespace Tests\Feature\CRM;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConversationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-customer-support', 'user');
    }

    public function test_crm_can_see_and_reply_to_a_repair_conversation_assigned_to_repairer(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
        ]);
        $crm = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'role' => 'CRM',
            'status' => 'active',
        ]);
        $crm->givePermissionTo('access-customer-support');
        $customer = User::factory()->create(['status' => 'active']);
        $repairer = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'role' => 'STAFF',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'assigned_to_id' => $repairer->id,
            'assigned_to_type' => 'repairer',
            'status' => 'in_progress',
            'priority' => 'medium',
            'last_message_at' => now(),
        ]);
        RepairRequest::create([
            'request_id' => 'REP-VIS-' . strtoupper(substr((string) str()->uuid(), 0, 8)),
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '09171234567',
            'shoe_type' => 'Sneakers',
            'brand' => 'Nike',
            'description' => 'Repair support visibility regression',
            'shop_owner_id' => $shopOwner->id,
            'user_id' => $customer->id,
            'assigned_repairer_id' => $repairer->id,
            'conversation_id' => $conversation->id,
            'images' => ['repair-requests/test.jpg'],
            'total' => 500,
            'final_total' => 500,
            'status' => 'pending',
            'delivery_method' => 'walk_in',
            'payment_enabled' => false,
            'payment_status' => 'unpaid',
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $customer->id,
            'sender_type' => 'customer',
            'content' => 'Please check my repair update.',
        ]);

        $this->actingAs($crm, 'user')
            ->getJson('/api/crm/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id]);

        $this->actingAs($crm, 'user')
            ->getJson("/api/crm/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('id', $conversation->id)
            ->assertJsonPath('messages.0.content', 'Please check my repair update.');

        $this->actingAs($crm, 'user')
            ->postJson("/api/crm/conversations/{$conversation->id}/messages", [
                'content' => 'CRM can continue this repair conversation.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation_id', $conversation->id);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'content' => 'CRM can continue this repair conversation.',
        ]);
    }

    public function test_crm_does_not_see_a_non_repair_conversation_assigned_to_shop_owner(): void
    {
        $shopOwner = ShopOwner::factory()->approved()->create([
            'business_type' => 'repair',
        ]);
        $crm = User::factory()->create([
            'shop_owner_id' => $shopOwner->id,
            'role' => 'CRM',
            'status' => 'active',
        ]);
        $crm->givePermissionTo('access-customer-support');
        $customer = User::factory()->create(['status' => 'active']);
        $conversation = Conversation::create([
            'shop_owner_id' => $shopOwner->id,
            'customer_id' => $customer->id,
            'assigned_to_type' => 'shop_owner',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        $response = $this->actingAs($crm, 'user')
            ->getJson('/api/crm/conversations')
            ->assertOk();

        $this->assertNotContains(
            $conversation->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }
}
