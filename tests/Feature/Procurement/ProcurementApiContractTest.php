<?php

namespace Tests\Feature\Procurement;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProcurementApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_mutations_use_the_standard_envelope(): void
    {
        config(['auth.defaults.guard' => 'user']);
        $owner = ShopOwner::factory()->create();
        $user = User::factory()->for($owner)->create();
        Permission::findOrCreate('procurement.manage_suppliers', 'user');
        $user->givePermissionTo('procurement.manage_suppliers');

        $created = $this->actingAs($user)->postJson('/api/erp/procurement/suppliers', ['name' => 'Local Supplier'])->assertCreated();
        $this->assertSame(['message', 'data'], array_keys($created->json()));
        $id = $created->json('data.id');

        $updated = $this->putJson("/api/erp/procurement/suppliers/{$id}", ['name' => 'Updated Supplier'])->assertOk();
        $this->assertSame(['message', 'data'], array_keys($updated->json()));

        $archived = $this->deleteJson("/api/erp/procurement/suppliers/{$id}")->assertOk();
        $this->assertSame(['message', 'data'], array_keys($archived->json()));

        $restored = $this->postJson("/api/erp/procurement/suppliers/{$id}/restore")->assertOk();
        $this->assertSame(['message', 'data'], array_keys($restored->json()));
    }
}
