<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;

trait AuthenticatesPrivilegedUsers
{
    protected function actingAsCompletedPrivileged(SuperAdmin $admin): static
    {
        $this->actingAs($admin, 'super_admin');
        $sessionId = session()->getId();
        session()->put([
            'privileged_auth_stage' => 'complete',
            'privileged_super_admin_id' => $admin->id,
            'privileged_security_version' => $admin->security_version,
        ]);

        PrivilegedSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'super_admin_id' => $admin->id,
                'security_version' => $admin->security_version,
                'authenticated_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        session()->save();

        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);

        return $this;
    }
}
