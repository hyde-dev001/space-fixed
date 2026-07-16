<?php

namespace Tests\Feature\UserSide;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerRegistrationAddressTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan.dela.cruz@gmail.com',
            'phone' => '09171234567',
            'age' => 25,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'address' => '123 Rizal Street, Ermita, Manila',
            'address_region' => 'National Capital Region',
            'address_province' => 'Metro Manila',
            'address_city' => 'Manila',
            'address_barangay' => 'Ermita',
            'address_postal_code' => '1000',
            'address_latitude' => 14.5832,
            'address_longitude' => 120.9822,
            'valid_id' => UploadedFile::fake()->create('valid-id.png', 10, 'image/png'),
        ], $overrides);
    }

    public function test_registration_creates_a_default_shipping_address(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => [
            'country_code' => 'ph',
            'region' => 'National Capital Region',
            'state' => 'Metro Manila',
            'city' => 'Manila',
            'suburb' => 'Ermita',
            'postcode' => '1000',
        ]])]);

        $this->post('/user/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $userId = (int) auth('web')->id();
        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $userId,
            'name' => 'Juan Dela Cruz',
            'phone' => '09171234567',
            'region' => 'National Capital Region',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
            'postal_code' => '1000',
            'address_line' => '123 Rizal Street, Ermita, Manila',
            'latitude' => 14.5832,
            'longitude' => 120.9822,
            'is_default' => true,
        ]);
    }

    public function test_registration_requires_a_complete_map_location(): void
    {
        Storage::fake('public');

        $this->post('/user/register', $this->payload([
            'address_region' => null,
            'address_province' => null,
            'address_city' => null,
            'address_barangay' => null,
            'address_latitude' => null,
            'address_longitude' => null,
        ]))->assertSessionHasErrors([
            'address_region',
            'address_province',
            'address_city',
            'address_barangay',
            'address_latitude',
            'address_longitude',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'juan.dela.cruz@gmail.com']);
    }

    public function test_registration_rejects_non_philippine_coordinates_inside_the_bounding_box(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => ['country_code' => 'my']])]);

        $this->post('/user/register', $this->payload([
            'address' => 'Kudat, Sabah, Malaysia',
            'address_region' => 'Sabah',
            'address_province' => 'Sabah',
            'address_city' => 'Kudat',
            'address_barangay' => 'Kudat',
            'address_latitude' => 7.0,
            'address_longitude' => 116.8,
        ]))->assertSessionHasErrors('address_latitude');

        $this->assertDatabaseMissing('users', ['email' => 'juan.dela.cruz@gmail.com']);
    }

    public function test_registration_uses_reverse_geocoded_shipping_fields_instead_of_client_values(): void
    {
        Storage::fake('public');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => [
            'country_code' => 'ph',
            'region' => 'National Capital Region',
            'state' => 'Metro Manila',
            'city' => 'Manila',
            'suburb' => 'Ermita',
            'postcode' => '1000',
        ]])]);

        $this->post('/user/register', $this->payload([
            'address_region' => 'Forged Region',
            'address_province' => 'Forged Province',
            'address_city' => 'Forged City',
            'address_barangay' => 'Forged Barangay',
        ]))->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('user_addresses', [
            'region' => 'National Capital Region',
            'province' => 'Metro Manila',
            'city' => 'Manila',
            'barangay' => 'Ermita',
        ]);
        $this->assertDatabaseMissing('user_addresses', ['city' => 'Forged City']);
    }
}
