<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryIncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_reports_incident_with_validated_private_uploads_and_no_raw_paths(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$shop, $leg, $rider] = $this->assignedLeg();

        $response = $this->actingAs($rider, 'user')
            ->post("/api/logistics/legs/{$leg->id}/incidents", [
                'type' => 'damaged',
                'notes' => 'Parcel was damaged during transport.',
                'photo_files' => [$this->fakeEvidenceFile('damage.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('incident.photo_paths', null);

        $incident = DeliveryIncident::query()->findOrFail($response->json('incident.id'));
        $path = $incident->photo_paths[0];
        $this->assertStringStartsWith('incident-evidence/leg-', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringContainsString('/api/logistics/incidents/'.$incident->id.'/evidence/0', $response->json('incident.evidence_urls.0'));
    }

    public function test_client_supplied_incident_paths_are_rejected(): void
    {
        [$shop, $leg, $rider] = $this->assignedLeg();

        $this->actingAs($rider, 'user')
            ->postJson("/api/logistics/legs/{$leg->id}/incidents", [
                'type' => 'lost',
                'notes' => 'Parcel is missing.',
                'photo_paths' => ['incident-evidence/leg-1/external.jpg'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo_files');
    }

    public function test_loss_resolution_uploads_private_evidence_and_only_authorized_staff_can_download_it(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$shop, $leg, $rider] = $this->assignedLeg();
        $incident = DeliveryIncident::factory()->create([
            'shop_owner_id' => $shop->id,
            'shipment_leg_id' => $leg->id,
            'reporting_rider_profile_id' => $leg->assignments()->value('rider_profile_id'),
            'type' => 'lost',
            'status' => 'reported',
            'photo_paths' => [],
        ]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        $otherShop = ShopOwner::factory()->create();
        $otherStaff = User::factory()->create(['shop_owner_id' => $otherShop->id]);
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $staff->givePermissionTo('resolve-logistics-exceptions');
        $otherStaff->givePermissionTo('resolve-logistics-exceptions');

        $response = $this->actingAs($staff, 'user')
            ->post("/api/logistics/incidents/{$incident->id}/resolve", [
                'resolution' => 'loss_confirmed',
                'note' => 'Search and investigation completed.',
                'evidence_files' => [$this->fakeEvidenceFile('investigation.png')],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('incident.photo_paths', null);

        $updated = $incident->fresh();
        $path = $updated->photo_paths[0];
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $url = "/api/logistics/incidents/{$incident->id}/evidence/0";
        $this->actingAs($staff, 'user')->get($url)->assertOk()->assertStreamedContent(
            Storage::disk('local')->get($path),
        );
        $this->actingAs($otherStaff, 'user')->get($url)->assertForbidden();
        $this->assertStringContainsString('/api/logistics/incidents/'.$incident->id.'/evidence/0', $response->json('incident.evidence_urls.0'));
    }

    public function test_unknown_incident_resolution_is_rejected_by_the_api(): void
    {
        [$shop, $leg] = $this->assignedLeg();
        $incident = DeliveryIncident::factory()->create([
            'shop_owner_id' => $shop->id,
            'shipment_leg_id' => $leg->id,
            'reporting_rider_profile_id' => $leg->assignments()->value('rider_profile_id'),
            'type' => 'damaged',
            'status' => 'reported',
        ]);
        $staff = User::factory()->create(['shop_owner_id' => $shop->id]);
        Permission::findOrCreate('resolve-logistics-exceptions', 'user');
        $staff->givePermissionTo('resolve-logistics-exceptions');

        $this->actingAs($staff, 'user')
            ->postJson("/api/logistics/incidents/{$incident->id}/resolve", [
                'resolution' => 'invented_resolution',
                'note' => 'Not supported.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resolution');
    }

    private function assignedLeg(): array
    {
        $shop = ShopOwner::factory()->create();
        $shipment = Shipment::factory()->create(['shop_owner_id' => $shop->id]);
        $leg = ShipmentLeg::factory()->create(['shipment_id' => $shipment->id, 'status' => 'picked_up']);
        $rider = User::factory()->create(['shop_owner_id' => $shop->id]);
        $profile = RiderProfile::factory()->create([
            'shop_owner_id' => $shop->id,
            'linked_type' => User::class,
            'linked_id' => $rider->id,
        ]);
        $leg->assignments()->create([
            'assignment_type' => 'internal_rider',
            'rider_profile_id' => $profile->id,
            'status' => 'accepted',
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);

        return [$shop, $leg, $rider];
    }

    private function fakeEvidenceFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }
}
