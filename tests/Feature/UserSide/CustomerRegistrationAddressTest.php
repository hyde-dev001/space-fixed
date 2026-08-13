<?php

namespace Tests\Feature\UserSide;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
                'country_code' => 'ph',
                'region' => 'National Capital Region',
                'state' => 'Metro Manila',
                'city' => 'Manila',
                'suburb' => 'Ermita',
                'postcode' => '1000',
            ]])]);

        $this->post('/user/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $user = User::query()
            ->where('email', 'juan.dela.cruz@gmail.com')
            ->firstOrFail();

        $this->assertAuthenticatedAs($user, 'user');
        $this->assertGuest('web');

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
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

        $this->getJson(route('user.addresses.index'))
            ->assertOk();
    }

    public function test_signed_verification_authenticates_customer_on_user_guard(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => 'active',
        ]);
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->get($verificationUrl)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user, 'user');
        $this->assertGuest('web');
    }

    public function test_unverified_customer_login_uses_user_guard_for_verification_notice(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified.customer@example.test',
            'status' => 'active',
        ]);

        $this->post('/user/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($user, 'user');
        $this->assertGuest('web');
        $this->get(route('verification.notice'))->assertOk();
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
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '7.0',
            'lon' => '116.8',
            'address' => ['country_code' => 'my'],
        ])]);

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
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'lat' => '14.5832',
            'lon' => '120.9822',
            'address' => [
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
