<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('production')) {
            User::factory(120)->create();
        }

        $this->call([
            ShopOwnerSeeder::class,
            RolesAndPermissionsSeeder::class,
            EmployeeSeeder::class,
            PayrollMasterDataSeeder::class,
            PremiumPlanSeeder::class,
            TaxRateSeeder::class,
            PayrollStatutoryTaxRateSeeder::class,
        ]);

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
