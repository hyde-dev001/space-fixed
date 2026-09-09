<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\HR\AuditLog;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EmployeeSecurityService
{
    public function markAuthenticated(Request $request, User $user): void
    {
        $request->session()->put('employee_security_version', (int) ($user->security_version ?: 1));
    }

    public function invalidateAccess(User $user, ?Request $preserveRequest = null): int
    {
        $version = max(1, (int) ($user->security_version ?: 1)) + 1;

        $user->forceFill(['security_version' => $version])->save();
        $user->tokens()->delete();
        $this->deleteDatabaseSessions($user, $preserveRequest);

        return $version;
    }

    public function paginateActiveSessions(Request $request, User $user, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        if (! $this->usesDatabaseSessions()) {
            return new LengthAwarePaginator(
                [$this->currentSessionPayload($request)],
                1,
                $perPage,
                $page,
                ['path' => $request->url()],
            );
        }

        $currentSessionId = $request->session()->getId();
        $paginator = $this->activeSessionQuery($user)->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(
            fn (object $session): array => $this->sessionPayload($session, $currentSessionId),
        ));

        return $paginator;
    }

    public function activeSessions(Request $request, User $user): array
    {
        if (! $this->usesDatabaseSessions()) {
            return [$this->currentSessionPayload($request)];
        }

        $currentSessionId = $request->session()->getId();

        return $this->activeSessionQuery($user)->limit(5)->get()->map(fn (object $session): array => $this->sessionPayload($session, $currentSessionId))->values()->all();
    }

    public function logoutOtherSessions(Request $request, User $user): int
    {
        return DB::transaction(function () use ($request, $user): int {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser) {
                return 0;
            }

            $removed = 0;
            if ($this->usesDatabaseSessions()) {
                $sessionId = $request->session()->getId();
                if ($sessionId !== '') {
                    $removed = (int) DB::table($this->sessionTable())
                        ->where('user_id', $lockedUser->getKey())
                        ->where('id', '<>', $sessionId)
                        ->delete();
                }
            }

            $this->invalidateAccess($lockedUser, $request);

            return $removed;
        });
    }

    public function audit(
        User $target,
        string $action,
        string $description,
        ?Employee $employee = null,
        string $severity = AuditLog::SEVERITY_WARNING,
    ): void {
        AuditLog::createLog([
            'shop_owner_id' => $target->shop_owner_id,
            'employee_id' => $employee?->getKey(),
            'module' => AuditLog::MODULE_EMPLOYEE,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $target->getKey(),
            'description' => $description,
            'severity' => $severity,
            'tags' => ['employee_security'],
        ]);
    }

    private function deleteDatabaseSessions(User $user, ?Request $preserveRequest): void
    {
        if (! $this->usesDatabaseSessions()) {
            return;
        }

        $query = DB::table($this->sessionTable())
            ->where('user_id', $user->getKey());

        if ($preserveRequest) {
            $sessionId = $preserveRequest->session()->getId();
            if ($sessionId !== '') {
                $query->where('id', '<>', $sessionId);
            }
        }

        $query->delete();
    }

    private function activeSessionQuery(User $user)
    {
        $cutoff = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

        return DB::table($this->sessionTable())
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $cutoff)
            ->orderByDesc('last_activity')
            ->select(['id', 'user_agent', 'last_activity']);
    }

    private function sessionPayload(object $session, string $currentSessionId): array
    {
        return [
            'device' => $this->deviceLabel((string) $session->user_agent),
            'last_active_at' => date(DATE_ATOM, (int) $session->last_activity),
            'current' => (string) $session->id === $currentSessionId,
        ];
    }

    private function currentSessionPayload(Request $request): array
    {
        return [
            'device' => $this->deviceLabel((string) $request->userAgent()),
            'last_active_at' => now()->toAtomString(),
            'current' => true,
        ];
    }

    private function usesDatabaseSessions(): bool
    {
        return config('session.driver') === 'database'
            && Schema::hasTable($this->sessionTable());
    }

    private function sessionTable(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function deviceLabel(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return "{$browser} / {$platform}";
    }

}
