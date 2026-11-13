<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrivilegedSession;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class PrivilegedSessionService
{
    public function establish(Request $request, SuperAdmin $admin): void
    {
        $sessionId = $this->sessionId($request);

        $request->session()->put([
            'privileged_auth_stage' => 'complete',
            'privileged_super_admin_id' => $admin->id,
            'privileged_security_version' => (int) $admin->security_version,
        ]);

        PrivilegedSession::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'super_admin_id' => $admin->id,
                'security_version' => (int) $admin->security_version,
                'authenticated_at' => now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function validate(Request $request, SuperAdmin $admin): bool
    {
        $sessionId = $this->sessionId($request);

        if ($request->session()->get('privileged_auth_stage') !== 'complete'
            || (int) $request->session()->get('privileged_super_admin_id') !== (int) $admin->id
            || (int) $request->session()->get('privileged_security_version') !== (int) $admin->security_version) {
            return false;
        }

        $session = PrivilegedSession::query()
            ->whereKey($sessionId)
            ->where('super_admin_id', $admin->id)
            ->where('security_version', $admin->security_version)
            ->first();

        if (! $session instanceof PrivilegedSession) {
            return false;
        }

        $session->forceFill(['last_seen_at' => now()])->saveQuietly();

        return true;
    }

    public function invalidateAllAfterCommit(SuperAdmin $admin): void
    {
        $sessionIds = PrivilegedSession::query()
            ->where('super_admin_id', $admin->id)
            ->pluck('session_id')
            ->all();

        DB::afterCommit(function () use ($sessionIds): void {
            $this->cleanup($sessionIds);
        });
    }

    public function invalidateOthersAfterCommit(Request $request, SuperAdmin $admin): void
    {
        $currentSessionId = $this->sessionId($request);
        $otherSessionIds = PrivilegedSession::query()
            ->where('super_admin_id', $admin->id)
            ->where('session_id', '<>', $currentSessionId)
            ->pluck('session_id')
            ->all();

        DB::afterCommit(function () use ($request, $admin, $otherSessionIds): void {
            $this->cleanup($otherSessionIds);
            $this->establish($request, $admin);
        });
    }

    public function forgetCurrent(Request $request): void
    {
        $sessionId = $this->sessionId($request);
        PrivilegedSession::query()->whereKey($sessionId)->delete();

        if (config('session.driver') === 'database') {
            try {
                DB::table((string) config('session.table', 'sessions'))->where('id', $sessionId)->delete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $request->session()->invalidate();
    }

    /** @param list<string> $sessionIds */
    private function cleanup(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }

        PrivilegedSession::query()->whereIn('session_id', $sessionIds)->delete();

        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::table((string) config('session.table', 'sessions'))->whereIn('id', $sessionIds)->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sessionId(Request $request): string
    {
        $sessionId = $request->session()->getId();

        if ($sessionId === '') {
            throw new InvalidArgumentException('A Laravel session is required.');
        }

        return $sessionId;
    }
}
