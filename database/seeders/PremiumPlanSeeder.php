<?php

namespace Database\Seeders;

use App\Models\PremiumPlan;
use Illuminate\Database\Seeder;

class PremiumPlanSeeder extends Seeder
{
    /**
     * Seed the default premium showroom plans.
     */
    public function run(): void
    {
        $plans = [
            [
                'plan_code' => 'basic',
                'name' => 'Basic',
                'description' => 'Best for getting started with virtual showroom access.',
                'price' => 249,
                'duration_days' => 15,
                'showroom_slot_limit' => 48,
                'benefits' => [
                    'View shoes in horizontal detail inside the showroom',
                    'Enable image-sequence uploads for showroom presentation',
                ],
                'status' => 'active',
            ],
            [
                'plan_code' => 'pro',
                'name' => 'Pro',
                'description' => 'Ideal for ongoing premium showroom access and higher slot capacity.',
                'price' => 399,
                'duration_days' => 30,
                'showroom_slot_limit' => 60,
                'benefits' => [
                    'View shoes in horizontal detail inside the showroom',
                    'Enable image-sequence uploads for showroom presentation',
                ],
                'status' => 'active',
            ],
            [
                'plan_code' => 'premium',
                'name' => 'Premium',
                'description' => 'Best value for long-term premium showroom visibility.',
                'price' => 599,
                'duration_days' => 30,
                'showroom_slot_limit' => 84,
                'benefits' => [
                    'View shoes in horizontal detail inside the showroom',
                    'Enable image-sequence uploads for showroom presentation',
                ],
                'status' => 'active',
            ],
        ];

        foreach ($plans as $plan) {
            PremiumPlan::updateOrCreate(
                ['plan_code' => $plan['plan_code']],
                $plan,
            );
        }
    }
}
