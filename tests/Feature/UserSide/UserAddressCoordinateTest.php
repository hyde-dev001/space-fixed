<?php

namespace Tests\Feature\UserSide;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserAddressCoordinateTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return [...[
            'name' => 'Customer', 'phone' => '09171234567', 'region' => 'NCR',
            'province' => 'Metro Manila', 'city' => 'Manila', 'barangay' => 'Ermita',
            'postal_code' => '1000', 'address_line' => '1 Test Street',
        ], ...$extra];
    }

    public function test_valid_coordinates_are_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'user')->postJson('/api/user/addresses', $this->payload([
            'latitude' => 14.5995, 'longitude' => 120.9842, 'delivery_instructions' => 'Blue gate',
        ]))->assertCreated();

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id, 'latitude' => 14.5995, 'longitude' => 120.9842,
            'delivery_instructions' => 'Blue gate',
        ]);
    }

    public function test_partial_or_invalid_coordinates_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'user')->postJson('/api/user/addresses', $this->payload(['latitude' => 14]))
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/user/addresses', $this->payload(['latitude' => 91, 'longitude' => 120]))
            ->assertUnprocessable()->assertJsonValidationErrors('latitude');
    }

    public function test_missing_coordinates_are_geocoded_and_failure_keeps_them_null(): void
    {
        $user = User::factory()->create();
        Http::fakeSequence()
            ->push([['lat' => '14.5995', 'lon' => '120.9842']])
            ->push([], 200)->push([], 200)->push([], 200)->push([], 200);

        $first = $this->actingAs($user, 'user')->postJson('/api/user/addresses', $this->payload())->assertCreated()->json('address');
        $second = $this->postJson('/api/user/addresses', $this->payload(['address_line' => 'Unknown']))->assertCreated()->json('address');

        $this->assertSame('14.59950000', $first['latitude']);
        $this->assertNull($second['latitude']);
    }
}
