<?php

namespace Tests\Feature\Auth;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopOwnerPendingApprovalSignedLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function signed_pending_approval_link_renders_pending_page(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'pending',
            'password' => null,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.pending-approval.link',
            now()->addMinutes(30),
            ['shopOwner' => $shopOwner->id]
        );

        $response = $this->get($signedUrl);

        $response->assertOk();
        $response->assertSee('Auth/PendingApproval', false);
    }

    #[Test]
    public function signed_pending_approval_link_rejects_tampered_signature(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'pending',
            'password' => null,
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.pending-approval.link',
            now()->addMinutes(30),
            ['shopOwner' => $shopOwner->id]
        );

        $response = $this->get($signedUrl . '&tampered=1');

        $response->assertForbidden();
    }

    #[Test]
    public function signed_pending_approval_link_redirects_approved_accounts_to_login(): void
    {
        $shopOwner = ShopOwner::factory()->create([
            'status' => 'approved',
            'password' => Hash::make('Password1!'),
        ]);

        $signedUrl = URL::temporarySignedRoute(
            'shop-owner.pending-approval.link',
            now()->addMinutes(30),
            ['shopOwner' => $shopOwner->id]
        );

        $response = $this->get($signedUrl);

        $response->assertRedirect(route('shop-owner.login.form'));
    }
}
