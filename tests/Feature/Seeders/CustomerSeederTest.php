<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_seeder_marks_demo_customers_as_identity_approved(): void
    {
        $this->seed(CustomerSeeder::class);

        $customers = User::query()
            ->whereIn('email', [
                'miguel.rosa@example.com',
                'maria.santos@example.com',
                'john.tan@example.com',
                'roberto.cruz@example.com',
                'patricia.reyes@example.com',
                'carlos.mendoza@example.com',
                'anna.garcia@example.com',
                'eduardo.lopez@example.com',
                'suspended.customer@example.com',
                'inactive.customer@example.com',
                'newbie.customer@example.com',
                'frequent.customer@example.com',
            ])
            ->get();

        $this->assertCount(12, $customers);

        foreach ($customers as $customer) {
            $this->assertSame(User::IDENTITY_APPROVED, $customer->identity_verification_status);
        }
    }
}
