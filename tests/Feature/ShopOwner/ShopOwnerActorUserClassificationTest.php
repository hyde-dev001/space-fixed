<?php

namespace Tests\Feature\ShopOwner;

use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ShopOwnerActorUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;

class ShopOwnerActorUserClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_owner_proxy_has_owner_identity_and_no_employee_record(): void
    {
        Role::findOrCreate('Shop Owner', 'user');
        $owner = ShopOwner::factory()->approved()->create();
        $resolver = app(ShopOwnerActorUserResolver::class);
        $id = $resolver->ensure($owner);
        $actor = User::query()->findOrFail($id);
        $this->assertNull($actor->role);
        $this->assertTrue($actor->hasRole('Shop Owner'));
        $this->assertSame($owner->id, $actor->shop_owner_id);
        $this->assertNull($actor->employee);
        $this->assertSame($id, $resolver->ensure($owner));
    }

    public function test_missing_canonical_owner_role_is_created_before_returning_actor(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $id = app(ShopOwnerActorUserResolver::class)->ensure($owner);
        $actor = User::query()->findOrFail($id);
        $this->assertNull($actor->role);
        $this->assertTrue($actor->hasRole('Shop Owner'));
        $this->assertSame($id, app(ShopOwnerActorUserResolver::class)->resolve($owner->id));
    }

    public function test_existing_actor_and_employee_history_are_not_bulk_reclassified(): void
    {
        Role::findOrCreate('Shop Owner', 'user');
        $owner = ShopOwner::factory()->approved()->create();
        $actor = User::factory()->create(['shop_owner_id' => $owner->id, 'role' => 'STAFF']);
        $actor->assignRole('Shop Owner');
        $employee = Employee::factory()->active()->create(['shop_owner_id' => $owner->id, 'email' => $actor->email]);
        $this->assertSame($actor->id, app(ShopOwnerActorUserResolver::class)->ensure($owner));
        $this->assertSame('STAFF', $actor->fresh()->role);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_same_email_in_another_shop_is_not_adopted_as_the_owner_actor(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $other = ShopOwner::factory()->approved()->create();
        $unrelated = User::factory()->create(['shop_owner_id' => $other->id, 'email' => $owner->email]);
        $id = app(ShopOwnerActorUserResolver::class)->ensure($owner);
        $this->assertNotSame($unrelated->id, $id);
        $this->assertSame($other->id, $unrelated->fresh()->shop_owner_id);
        $this->assertSame($owner->id, User::query()->findOrFail($id)->shop_owner_id);
    }

    public function test_role_assignment_failure_does_not_leave_an_ambiguous_proxy(): void
    {
        $owner = ShopOwner::factory()->approved()->create();
        $event = 'eloquent.creating: '.Role::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('Role storage unavailable');
        });
        try {
            $this->assertNull(app(ShopOwnerActorUserResolver::class)->ensure($owner));
            $this->assertDatabaseMissing('users', ['email' => 'shopowner+'.$owner->id.'@solespace.local']);
        } finally {
            Event::forget($event);
        }
    }
}
