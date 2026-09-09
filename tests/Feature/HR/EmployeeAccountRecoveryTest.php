<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Mail\EmployeeInvitation;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EmployeeAccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shop;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('access-employee-directory', 'user');
        Role::findOrCreate('HR', 'user');

        $this->shop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $this->hr = User::factory()->for($this->shop)->create([
            'role' => 'HR',
            'status' => 'active',
        ]);
        $this->hr->assignRole('HR');
        $this->hr->givePermissionTo('access-employee-directory');
    }

    #[Test]
    public function hr_can_issue_one_setup_link_and_send_it_to_a_delivery_only_personal_address(): void
    {
        Mail::fake();
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();

        $reset = $this->actingAs($this->hr, 'user')
            ->postJson("/api/hr/employees/{$employee->id}/reset-password")
            ->assertOk()
            ->assertJsonStructure([
                'invite_url',
                'invite_expires_at',
                'work_email',
                'employee_name',
            ]);

        $linkedUser->refresh();
        $this->assertNull($linkedUser->password);
        $this->assertTrue($linkedUser->force_password_change);
        $this->assertNotNull($linkedUser->invite_token);

        $personalEmail = 'personal-only@example.test';
        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/employees/' . $employee->id . '/send-invitation-email', [
                'personal_email' => $personalEmail,
            ])
            ->assertOk();

        Mail::assertSent(
            EmployeeInvitation::class,
            fn (EmployeeInvitation $mail): bool => $mail->hasTo($personalEmail)
                && $mail->inviteUrl === $reset->json('invite_url'),
        );
        $this->assertSame($employee->email, $employee->fresh()->email);
        $this->assertSame($employee->email, $linkedUser->fresh()->email);
        $this->assertDatabaseHas('audit_logs', [
            'shop_owner_id' => $this->shop->id,
            'action' => 'employee_password_reset',
        ]);
    }

    #[Test]
    public function shop_owner_can_use_the_same_reset_and_personal_delivery_flow_for_a_same_shop_employee(): void
    {
        Mail::fake();
        [$employee, $linkedUser] = $this->employeeWithLinkedUser();

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson('/api/shop-owner/employees/' . $linkedUser->id . '/reset-password')
            ->assertOk()
            ->assertJsonPath('work_email', $employee->email);

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson('/api/shop-owner/employees/' . $linkedUser->id . '/send-invitation-email', [
                'personal_email' => 'owner-delivery@example.test',
            ])
            ->assertOk();

        Mail::assertSent(
            EmployeeInvitation::class,
            fn (EmployeeInvitation $mail): bool => $mail->hasTo('owner-delivery@example.test'),
        );
        $this->assertNull($linkedUser->fresh()->password);
    }

    #[Test]
    public function account_recovery_cannot_cross_shop_boundaries(): void
    {
        $foreignShop = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $foreignEmployee = Employee::factory()->for($foreignShop)->create();
        $foreignUser = User::factory()->for($foreignShop)->create([
            'email' => $foreignEmployee->email,
        ]);

        $this->actingAs($this->hr, 'user')
            ->postJson('/api/hr/employees/' . $foreignEmployee->id . '/reset-password')
            ->assertNotFound();

        $this->actingAs($this->shop, 'shop_owner')
            ->postJson('/api/shop-owner/employees/' . $foreignUser->id . '/reset-password')
            ->assertNotFound();
    }

    /** @return array{0: Employee, 1: User} */
    private function employeeWithLinkedUser(): array
    {
        $email = 'employee-' . fake()->unique()->safeEmail;
        $employee = Employee::factory()->for($this->shop)->create([
            'email' => $email,
        ]);
        $linkedUser = User::factory()->for($this->shop)->create([
            'email' => $email,
            'status' => 'active',
            'role' => 'Staff',
        ]);

        return [$employee, $linkedUser];
    }
}
