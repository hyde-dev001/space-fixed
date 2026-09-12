<?php

namespace Database\Factories;

use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuperAdmin>
 */
class SuperAdminFactory extends Factory
{
    protected $model = SuperAdmin::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'TestPassword1!',
            'phone' => fake()->phoneNumber(),
            'role' => SuperAdmin::ROLE_ADMIN,
            'status' => SuperAdmin::STATUS_ACTIVE,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SuperAdmin $admin): void {
            if ($admin->status !== SuperAdmin::STATUS_PENDING_SETUP
                && $admin->mfa_secret === null
                && $admin->mfa_recovery_codes === null
                && $admin->mfa_confirmed_at === null) {
                $admin->forceFill($this->enrolledAttributes());
            }
        });
    }

    public function admin(): static
    {
        return $this->state(['role' => SuperAdmin::ROLE_ADMIN]);
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => SuperAdmin::ROLE_SUPER_ADMIN]);
    }

    public function pendingSetup(): static
    {
        return $this->state([
            'status' => SuperAdmin::STATUS_PENDING_SETUP,
            'password' => Hash::make(Str::random(64)),
        ])->afterMaking(function (SuperAdmin $admin): void {
            $admin->forceFill($this->withoutMfaAttributes());
        });
    }

    public function activeWithoutMfa(): static
    {
        return $this->state(['status' => SuperAdmin::STATUS_ACTIVE])
            ->afterMaking(function (SuperAdmin $admin): void {
                $admin->forceFill($this->withoutMfaAttributes());
            });
    }

    public function mfaEnrolled(): static
    {
        return $this->state(['status' => SuperAdmin::STATUS_ACTIVE])
            ->afterMaking(function (SuperAdmin $admin): void {
                $attributes = $this->enrolledAttributes();

                foreach (array_keys($attributes) as $attribute) {
                    if ($admin->getAttribute($attribute) !== null) {
                        $attributes[$attribute] = $admin->getAttribute($attribute);
                    }
                }

                $admin->forceFill($attributes);
            });
    }

    public function suspended(): static
    {
        return $this->state(['status' => SuperAdmin::STATUS_SUSPENDED]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => SuperAdmin::STATUS_INACTIVE]);
    }

    /** @return array<string, mixed> */
    private function enrolledAttributes(): array
    {
        return [
            'mfa_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
            'mfa_recovery_codes' => array_map(
                fn (int $number): string => Hash::make("factory-recovery-{$number}"),
                range(1, 8),
            ),
            'mfa_confirmed_at' => now(),
            'mfa_last_used_timestep' => null,
            'security_version' => 1,
            'password_changed_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function withoutMfaAttributes(): array
    {
        return [
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
            'mfa_last_used_timestep' => null,
            'security_version' => 1,
            'password_changed_at' => null,
        ];
    }
}
