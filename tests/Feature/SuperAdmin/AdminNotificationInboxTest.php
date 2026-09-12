<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\AuthenticatesPrivilegedUsers;
use Tests\TestCase;

final class AdminNotificationInboxTest extends TestCase
{
    use AuthenticatesPrivilegedUsers;
    use RefreshDatabase;

    public function test_the_inbox_requires_an_active_mfa_completed_privileged_session(): void
    {
        $this->get('/admin/notifications')
            ->assertRedirect('/admin/login');

        $withoutMfa = SuperAdmin::factory()->admin()->activeWithoutMfa()->create();

        $this->actingAsCompletedPrivileged($withoutMfa)
            ->get('/admin/notifications')
            ->assertRedirect();

        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $this->actingAsCompletedPrivileged($admin)
            ->get('/admin/notifications')
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('superAdmin/Notifications/AdminNotifications'));
    }

    public function test_list_is_recipient_scoped_newest_first_and_uses_bounded_integer_pagination(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $otherAdmin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $oldest = $this->notification($admin, [
            'title' => 'Oldest notification',
            'created_at' => '2026-08-12 09:00:00',
        ]);
        $newest = $this->notification($admin, [
            'title' => 'Newest notification',
            'created_at' => '2026-08-12 10:00:00',
        ]);
        $this->notification($otherAdmin, ['title' => 'Other administrator notification']);

        $response = $this->actingAsCompletedPrivileged($admin)
            ->getJson('/api/admin/notifications?per_page=999');

        $response
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('notifications.0.id', $newest->id)
            ->assertJsonPath('notifications.1.id', $oldest->id);

        $this->assertCount(2, $response->json('notifications'));
        $this->assertStringNotContainsString('Other administrator', $response->getContent());

        $fallbackResponse = $this->actingAsCompletedPrivileged($admin)
            ->getJson('/api/admin/notifications?per_page=not-an-integer');

        $fallbackResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 20);
    }

    public function test_unread_filter_and_response_serialization_are_explicit_and_safe(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();

        $valid = $this->notification($admin, [
            'title' => 'Review request',
            'action_url' => '/admin/audit?event=review',
            'data' => ['secret' => 'must-not-leak', 'subject_id' => 42],
            'is_read' => false,
            'read_at' => null,
        ]);
        $this->notification($admin, [
            'title' => 'Already read',
            'is_read' => true,
            'read_at' => '2026-08-12 08:00:00',
        ]);
        $unsafeUrls = [
            'https://external.example/notification',
            '//external.example/notification',
            'javascript:alert(1)',
            "/admin/audit\nX-Leak: yes",
        ];
        foreach ($unsafeUrls as $index => $actionUrl) {
            $this->notification($admin, [
                'title' => "Unsafe {$index}",
                'action_url' => $actionUrl,
            ]);
        }

        $response = $this->actingAsCompletedPrivileged($admin)
            ->getJson('/api/admin/notifications?unread_only=1');

        $response
            ->assertOk()
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('unread_count', 5);

        $rows = $response->json('notifications');
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame([
                'id',
                'type',
                'title',
                'message',
                'action_url',
                'is_read',
                'read_at',
                'created_at',
            ], array_keys($row));
        }

        $validRow = collect($rows)->firstWhere('id', $valid->id);
        $this->assertSame('/admin/audit?event=review', $validRow['action_url']);
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());

        foreach ($unsafeUrls as $index => $_) {
            $unsafeRow = collect($rows)->firstWhere('title', "Unsafe {$index}");
            $this->assertNull($unsafeRow['action_url']);
        }
    }

    public function test_single_mutations_and_mark_all_are_scoped_to_the_current_administrator(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $otherAdmin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $owned = $this->notification($admin, ['title' => 'Owned notification']);
        $other = $this->notification($otherAdmin, ['title' => 'Private notification']);

        $this->actingAsCompletedPrivileged($admin)
            ->postJson("/api/admin/notifications/{$owned->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertDatabaseHas('notifications', [
            'id' => $owned->id,
            'super_admin_id' => $admin->id,
            'is_read' => true,
        ]);

        $this->actingAsCompletedPrivileged($admin)
            ->deleteJson("/api/admin/notifications/{$other->id}")
            ->assertNotFound();

        $this->actingAsCompletedPrivileged($admin)
            ->postJson('/api/admin/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->assertDatabaseHas('notifications', [
            'id' => $other->id,
            'super_admin_id' => $otherAdmin->id,
            'is_read' => false,
        ]);
    }

    public function test_dismissing_a_notification_does_not_change_privileged_audit_history(): void
    {
        $admin = SuperAdmin::factory()->admin()->mfaEnrolled()->create();
        $notification = $this->notification($admin, ['title' => 'Dismiss me']);
        $activity = Activity::query()->create([
            'log_name' => 'privileged',
            'description' => 'existing audit record',
            'event' => 'existing_audit_record',
            'properties' => ['correlation_id' => 'phase-four-test'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeCount = Activity::query()->count();

        $this->actingAsCompletedPrivileged($admin)
            ->deleteJson("/api/admin/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Notification dismissed');

        $this->assertSame($beforeCount, Activity::query()->count());
        $this->assertDatabaseHas('activity_log', ['id' => $activity->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function notification(SuperAdmin $admin, array $overrides = []): Notification
    {
        return Notification::query()->create(array_merge([
            'super_admin_id' => $admin->id,
            'type' => NotificationType::SHOP_REGISTRATION_PENDING->value,
            'title' => 'Notification',
            'message' => 'An operational event requires review.',
            'data' => ['internal_id' => 7],
            'action_url' => '/admin/audit',
            'is_read' => false,
            'read_at' => null,
            'requires_action' => true,
            'priority' => 'high',
        ], $overrides));
    }
}
