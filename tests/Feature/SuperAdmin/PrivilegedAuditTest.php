<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PrivilegedAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_access_writes_normalized_http_event(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/permit.pdf',
            'status' => 'pending',
        ]);
        $request = Request::create('/private-document', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_CORRELATION_ID' => 'client-controlled-id',
        ]);

        app(\App\Services\PrivilegedAudit::class)->documentAccessInitiated(
            $request,
            $admin,
            $document,
            $shopOwner,
            'application/pdf',
            'inline',
        );

        $activity = Activity::query()->where('log_name', 'privileged')->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('document_access_initiated', $activity->event);
        $this->assertSame('document_access_initiated', $activity->description);
        $this->assertSame(SuperAdmin::class, $activity->causer_type);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(ShopDocument::class, $activity->subject_type);
        $this->assertSame($document->id, $activity->subject_id);
        $this->assertSame('super_admin', $properties['actor_type']);
        $this->assertSame('super_admin', $properties['actor_guard']);
        $this->assertSame($admin->id, $properties['actor_id']);
        $this->assertSame('super_admin', $properties['actor_role']);
        $this->assertSame('document_access_initiated', $properties['event']);
        $this->assertSame('shop_document', $properties['target_type']);
        $this->assertSame($document->id, $properties['target_id']);
        $this->assertSame('http', $properties['source']);
        $this->assertSame('203.0.113.7', $properties['ip_address']);
        $this->assertTrue(Str::isUuid($properties['correlation_id']));
        $this->assertNotSame('client-controlled-id', $properties['correlation_id']);
        $this->assertSame($shopOwner->id, $properties['shop_owner_id']);
        $this->assertSame('application/pdf', $properties['mime']);
        $this->assertSame('inline', $properties['disposition']);
        $this->assertArrayNotHasKey('password', $properties);
        $this->assertArrayNotHasKey('token', $properties);
        $this->assertArrayNotHasKey('path', $properties);
        $this->assertArrayNotHasKey('filename', $properties);
    }

    public function test_http_operations_share_a_server_correlation_id_per_request(): void
    {
        $admin = SuperAdmin::factory()->admin()->create();
        $shopOwner = ShopOwner::factory()->create();
        $firstDocument = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'mayors_permit',
            'file_path' => 'shop_documents/first.pdf',
            'status' => 'pending',
        ]);
        $secondDocument = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'bir_certificate',
            'file_path' => 'shop_documents/second.pdf',
            'status' => 'pending',
        ]);
        $request = Request::create('/private-document', 'GET');
        $audit = app(\App\Services\PrivilegedAudit::class);

        $audit->documentAccessInitiated($request, $admin, $firstDocument, $shopOwner, 'application/pdf', 'inline');
        $audit->documentAccessInitiated($request, $admin, $secondDocument, $shopOwner, 'application/pdf', 'inline');

        $correlationIds = Activity::query()
            ->where('log_name', 'privileged')
            ->latest('id')
            ->limit(2)
            ->get()
            ->map(fn (Activity $activity) => $activity->properties['correlation_id'])
            ->values();

        $this->assertCount(1, $correlationIds->unique());
        $this->assertTrue(Str::isUuid($correlationIds[0]));
        $this->assertSame($correlationIds[0], $correlationIds[1]);

        $newRequest = Request::create('/private-document', 'GET');
        $audit->documentAccessInitiated($newRequest, $admin, $firstDocument, $shopOwner, 'application/pdf', 'inline');
        $newCorrelationId = Activity::query()->latest('id')->firstOrFail()->properties['correlation_id'];

        $this->assertNotSame($correlationIds[0], $newCorrelationId);
    }

    public function test_customer_valid_id_access_records_the_customer_target(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $user = User::factory()->create();
        $request = Request::create('/valid-id', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.9']);

        app(\App\Services\PrivilegedAudit::class)->customerValidIdAccessInitiated(
            $request,
            $admin,
            $user,
            'image/jpeg',
            'inline',
        );

        $activity = Activity::query()->where('log_name', 'privileged')->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('customer_valid_id_access_initiated', $activity->event);
        $this->assertSame(User::class, $activity->subject_type);
        $this->assertSame($user->id, $activity->subject_id);
        $this->assertSame('user', $properties['target_type']);
        $this->assertSame($user->id, $properties['target_id']);
        $this->assertSame($user->id, $properties['customer_user_id']);
        $this->assertSame('http', $properties['source']);
        $this->assertSame('198.51.100.9', $properties['ip_address']);
    }

    public function test_console_credential_rotation_uses_operation_uuid_without_http_context(): void
    {
        $admin = SuperAdmin::factory()->superAdmin()->create();
        $operationId = (string) Str::uuid();

        app(\App\Services\PrivilegedAudit::class)->credentialRotatedByConsole($admin, $operationId);

        $activity = Activity::query()->where('log_name', 'privileged')->latest('id')->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame('super_admin_credential_rotated', $activity->event);
        $this->assertSame(SuperAdmin::class, $activity->subject_type);
        $this->assertSame($admin->id, $activity->subject_id);
        $this->assertSame('console', $properties['source']);
        $this->assertSame($operationId, $properties['correlation_id']);
        $this->assertNull($properties['ip_address']);
        $this->assertArrayNotHasKey('password', $properties);
        $this->assertArrayNotHasKey('current_password', $properties);
        $this->assertArrayNotHasKey('new_password', $properties);
        $this->assertArrayNotHasKey('token', $properties);
    }
}
