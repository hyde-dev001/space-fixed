<?php

namespace Tests\Feature\ShopOwner;

use App\Models\ShopOwner;
use App\Services\ShopOwnerSetupPlan;
use App\Services\ShopModuleProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_follow_all_six_account_types(): void
    {
        foreach (['individual', 'company'] as $registration) {
            foreach (['retail', 'repair', 'both'] as $business) {
                $owner = ShopOwner::factory()->approved()->create([
                    'registration_type' => $registration,
                    'business_type' => $business,
                ]);
                $modules = app(ShopModuleProvisioningService::class);
                $modules->initializeMissing($owner, $modules->eligibleKeysFor($owner));
                if ($registration === 'company' && $business !== 'repair') {
                    $owner->modules()->where('module_key', 'logistics')->update(['enabled' => true]);
                }
                $keys = array_column(app(ShopOwnerSetupPlan::class)->for($owner)['tasks'], 'key');

                $this->assertContains('operating_hours', $keys);
                $this->assertContains('paymongo', $keys);
                $this->assertContains('terms_policy', $keys);
                $this->assertSame($registration === 'company', in_array('first_employee', $keys, true));
                $this->assertSame($registration === 'company', in_array('xendit', $keys, true));
                $this->assertSame($business !== 'retail', in_array('repair_payment_policy', $keys, true));
                $this->assertSame($registration === 'company' && $business !== 'repair', in_array('cod', $keys, true));
            }
        }
    }

    public function test_configuration_status_comes_from_saved_state(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
            'monday_open' => null,
            'monday_close' => null,
            'paymongo_secret_key' => null,
        ]);
        $plan = app(ShopOwnerSetupPlan::class);
        $tasks = collect($plan->for($owner)['tasks'])->keyBy('key');
        $this->assertSame('incomplete', $tasks['operating_hours']['status']);
        $this->assertSame('incomplete', $tasks['paymongo']['status']);

        $owner->update(['monday_open' => '09:00', 'monday_close' => '17:00', 'paymongo_secret_key' => 'sk_test_12345678901234567890']);
        $tasks = collect($plan->for($owner->fresh())['tasks'])->keyBy('key');
        $this->assertSame('complete', $tasks['operating_hours']['status']);
        $this->assertSame('complete', $tasks['paymongo']['status']);

        $owner->update(['paymongo_secret_key' => null]);
        $tasks = collect($plan->for($owner->fresh())['tasks'])->keyBy('key');
        $this->assertSame('incomplete', $tasks['paymongo']['status']);
    }

    public function test_tutorial_progress_is_private_and_does_not_complete_configuration(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
            'paymongo_secret_key' => null,
        ]);
        $other = ShopOwner::factory()->approved()->create();

        $this->getJson('/shop-owner/setup-guide')->assertUnauthorized();
        $this->actingAs($owner, 'shop_owner')->postJson('/shop-owner/setup-guide/progress', [
            'task_key' => 'paymongo', 'status' => 'completed', 'step' => 3,
        ])->assertOk();
        $this->actingAs($owner, 'shop_owner')->getJson('/shop-owner/setup-guide')
            ->assertJsonPath('tutorials.paymongo.status', 'completed')
            ->assertJsonPath('tutorials.paymongo.step', 3)
            ->assertJsonFragment(['key' => 'paymongo', 'status' => 'incomplete']);

        $this->actingAs($other, 'shop_owner')->getJson('/shop-owner/setup-guide')
            ->assertJsonMissingPath('tutorials.paymongo');
        $this->actingAs($owner, 'shop_owner')->postJson('/shop-owner/setup-guide/progress', [
            'task_key' => 'not_a_task', 'status' => 'completed', 'step' => 1,
        ])->assertUnprocessable();
        $this->assertNull($owner->fresh()->paymongo_secret_key);
    }

    public function test_welcome_and_educational_completion_are_independent_of_required_progress(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'individual',
            'business_type' => 'retail',
        ]);

        $initial = $this->actingAs($owner, 'shop_owner')->getJson('/shop-owner/setup-guide')
            ->assertOk()
            ->assertJsonPath('welcome_seen', false);
        $total = $initial->json('total');

        $this->postJson('/shop-owner/setup-guide/progress', [
            'task_key' => 'articles', 'status' => 'completed', 'step' => 2,
        ])->assertOk();

        $this->getJson('/shop-owner/setup-guide')
            ->assertJsonFragment(['key' => 'articles', 'status' => 'complete'])
            ->assertJsonPath('total', $total)
            ->assertJsonPath('completed', 0);

        $this->postJson('/shop-owner/setup-guide/welcome', [])->assertOk();
        $this->getJson('/shop-owner/setup-guide')->assertJsonPath('welcome_seen', true);
    }

    public function test_disabled_modules_remove_their_tasks_without_losing_core_requirements(): void
    {
        $owner = ShopOwner::factory()->approved()->create([
            'registration_type' => 'company',
            'business_type' => 'both',
        ]);
        $modules = app(ShopModuleProvisioningService::class);
        $modules->initializeMissing($owner, $modules->eligibleKeysFor($owner));
        $owner->modules()->whereIn('module_key', ['hr_employees', 'procurement', 'logistics'])
            ->update(['enabled' => false]);

        $keys = array_column(app(ShopOwnerSetupPlan::class)->for($owner)['tasks'], 'key');

        $this->assertContains('operating_hours', $keys);
        $this->assertContains('paymongo', $keys);
        $this->assertContains('terms_policy', $keys);
        foreach (['first_employee', 'payroll_cutoff', 'attendance_geofence', 'xendit', 'cod'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }
}
