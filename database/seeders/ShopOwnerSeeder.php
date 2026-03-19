<?php

namespace Database\Seeders;

use App\Models\ShopOwner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ShopOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Shop Owner 1: Individual, Both retail and repair
        ShopOwner::updateOrCreate(
            ['email' => 'test@example.com'],
            array_merge([
                'first_name' => 'Test',
                'last_name' => 'ShopOwner',
                'phone' => '+1234567890',
                'password' => bcrypt('test@example.com'),
                'business_name' => 'Test Business',
                'business_address' => '123 Aguinaldo Highway, Imus, Cavite',
                'city_state' => 'Imus, Cavite',
                'postal_code' => '4103',
                'country' => 'Philippines',
                'business_type' => 'both',
                'registration_type' => 'individual',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'tuesday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'wednesday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'thursday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'friday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'saturday' => ['open' => '9:00 AM', 'close' => '5:00 PM'],
                    'sunday' => ['open' => '10:00 AM', 'close' => '4:00 PM'],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );

        // Shop Owner 2: Company, Both retail and repair
        ShopOwner::updateOrCreate(
            ['email' => 'test2@example.com'],
            array_merge([
                'first_name' => 'Second',
                'last_name' => 'TestShop',
                'phone' => '+0987654321',
                'password' => bcrypt('test2@example.com'),
                'business_name' => 'Urban Kicks Store',
                'business_address' => '456 Molino Boulevard, Bacoor, Cavite',
                'city_state' => 'Bacoor, Cavite',
                'postal_code' => '4102',
                'country' => 'Philippines',
                'business_type' => 'both',
                'registration_type' => 'company',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '10:00 AM', 'close' => '8:00 PM'],
                    'tuesday' => ['open' => '10:00 AM', 'close' => '8:00 PM'],
                    'wednesday' => ['open' => '10:00 AM', 'close' => '8:00 PM'],
                    'thursday' => ['open' => '10:00 AM', 'close' => '8:00 PM'],
                    'friday' => ['open' => '10:00 AM', 'close' => '10:00 PM'],
                    'saturday' => ['open' => '10:00 AM', 'close' => '10:00 PM'],
                    'sunday' => ['open' => '10:00 AM', 'close' => '8:00 PM'],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );

        // Shop Owner 3: Individual, Retail only
        ShopOwner::updateOrCreate(
            ['email' => 'retail.shop@example.com'],
            array_merge([
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'phone' => '+639171234567',
                'password' => bcrypt('retail.shop@example.com'),
                'business_name' => 'Sneaker Haven',
                'business_address' => '789 Salitran Road, Dasmariñas, Cavite',
                'city_state' => 'Dasmariñas, Cavite',
                'postal_code' => '4114',
                'country' => 'Philippines',
                'business_type' => 'retail',
                'registration_type' => 'individual',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '9:00 AM', 'close' => '6:00 PM'],
                    'tuesday' => ['open' => '9:00 AM', 'close' => '6:00 PM'],
                    'wednesday' => ['open' => '9:00 AM', 'close' => '6:00 PM'],
                    'thursday' => ['open' => '9:00 AM', 'close' => '6:00 PM'],
                    'friday' => ['open' => '9:00 AM', 'close' => '7:00 PM'],
                    'saturday' => ['open' => '9:00 AM', 'close' => '7:00 PM'],
                    'sunday' => ['open' => '11:00 AM', 'close' => '5:00 PM'],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );

        // Shop Owner 4: Company, Retail only
        ShopOwner::updateOrCreate(
            ['email' => 'premium.retail@example.com'],
            array_merge([
                'first_name' => 'John',
                'last_name' => 'Tan',
                'phone' => '+639189876543',
                'password' => bcrypt('premium.retail@example.com'),
                'business_name' => 'Premium Footwear Corp',
                'business_address' => '321 Governor\'s Drive, General Trias, Cavite',
                'city_state' => 'General Trias, Cavite',
                'postal_code' => '4107',
                'country' => 'Philippines',
                'business_type' => 'retail',
                'registration_type' => 'company',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '10:00 AM', 'close' => '9:00 PM'],
                    'tuesday' => ['open' => '10:00 AM', 'close' => '9:00 PM'],
                    'wednesday' => ['open' => '10:00 AM', 'close' => '9:00 PM'],
                    'thursday' => ['open' => '10:00 AM', 'close' => '9:00 PM'],
                    'friday' => ['open' => '10:00 AM', 'close' => '10:00 PM'],
                    'saturday' => ['open' => '10:00 AM', 'close' => '10:00 PM'],
                    'sunday' => ['open' => '10:00 AM', 'close' => '9:00 PM'],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );

        // Shop Owner 5: Individual, Repair only
        ShopOwner::updateOrCreate(
            ['email' => 'repair.expert@example.com'],
            array_merge([
                'first_name' => 'Roberto',
                'last_name' => 'Cruz',
                'phone' => '+639195551234',
                'password' => bcrypt('repair.expert@example.com'),
                'business_name' => 'Shoe Repair Expert',
                'business_address' => '555 P. Burgos Street, Trece Martires, Cavite',
                'city_state' => 'Trece Martires, Cavite',
                'postal_code' => '4109',
                'country' => 'Philippines',
                'business_type' => 'repair',
                'registration_type' => 'individual',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '8:00 AM', 'close' => '5:00 PM'],
                    'tuesday' => ['open' => '8:00 AM', 'close' => '5:00 PM'],
                    'wednesday' => ['open' => '8:00 AM', 'close' => '5:00 PM'],
                    'thursday' => ['open' => '8:00 AM', 'close' => '5:00 PM'],
                    'friday' => ['open' => '8:00 AM', 'close' => '5:00 PM'],
                    'saturday' => ['open' => '8:00 AM', 'close' => '2:00 PM'],
                    'sunday' => ['is_closed' => true],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );

        // Shop Owner 6: Company, Repair only
        ShopOwner::updateOrCreate(
            ['email' => 'pro.repair@example.com'],
            array_merge([
                'first_name' => 'Patricia',
                'last_name' => 'Reyes',
                'phone' => '+639176667890',
                'password' => bcrypt('pro.repair@example.com'),
                'business_name' => 'ProShoe Restoration Services Inc.',
                'business_address' => '888 Aguinaldo Highway, Silang, Cavite',
                'city_state' => 'Silang, Cavite',
                'postal_code' => '4118',
                'country' => 'Philippines',
                'business_type' => 'repair',
                'registration_type' => 'company',
            ], $this->buildOperatingHoursAttributes([
                    'monday' => ['open' => '7:00 AM', 'close' => '7:00 PM'],
                    'tuesday' => ['open' => '7:00 AM', 'close' => '7:00 PM'],
                    'wednesday' => ['open' => '7:00 AM', 'close' => '7:00 PM'],
                    'thursday' => ['open' => '7:00 AM', 'close' => '7:00 PM'],
                    'friday' => ['open' => '7:00 AM', 'close' => '7:00 PM'],
                    'saturday' => ['open' => '8:00 AM', 'close' => '4:00 PM'],
                    'sunday' => ['is_closed' => true],
                ]), [
                'status' => 'approved',
                'rejection_reason' => null,
            ])
        );
    }

    private function buildOperatingHoursAttributes(array $schedule): array
    {
        $attributes = [
            'operating_hours' => [],
        ];

        foreach ($schedule as $day => $hours) {
            $isClosed = $this->isClosedDay($hours);
            $open = $isClosed ? null : $this->normalizeTime($hours['open']);
            $close = $isClosed ? null : $this->normalizeTime($hours['close']);

            $attributes['operating_hours'][$day] = $isClosed
                ? [
                    'open' => null,
                    'close' => null,
                    'is_closed' => true,
                ]
                : [
                    'open' => $open,
                    'close' => $close,
                ];

            $attributes[$day . '_open'] = $open;
            $attributes[$day . '_close'] = $close;
        }

        return $attributes;
    }

    private function isClosedDay(array $hours): bool
    {
        if (($hours['is_closed'] ?? false) === true) {
            return true;
        }

        $open = $hours['open'] ?? null;
        $close = $hours['close'] ?? null;

        if (empty($open) || empty($close)) {
            return true;
        }

        $normalizedOpen = $this->normalizeTime($open);
        $normalizedClose = $this->normalizeTime($close);

        return $normalizedOpen === '00:00' && $normalizedClose === '00:00';
    }

    private function normalizeTime(string $time): string
    {
        $normalizedTime = strtoupper(trim($time));

        foreach (['g:i A', 'h:i A', 'H:i', 'H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $normalizedTime)->format('H:i');
            } catch (\Throwable $e) {
                continue;
            }
        }

        return Carbon::parse($normalizedTime)->format('H:i');
    }
}
