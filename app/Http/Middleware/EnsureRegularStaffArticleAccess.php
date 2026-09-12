<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRegularStaffArticleAccess
{
    /**
     * Permissions that identify the regular Staff article audience.
     *
     * @var list<string>
     */
    public const STAFF_PERMISSIONS = [
        'access-staff-dashboard',
        'access-staff-job-orders',
        'access-product-management',
        'access-product-upload-staff',
        'access-shoe-pricing',
        'access-staff-time-in',
        'access-staff-leave',
        'access-color-variant-manager',
        'access-staff-customers',
    ];

    /**
     * Technical roles that receive separate article catalogs or no catalog.
     *
     * @var list<string>
     */
    private const EXCLUDED_ROLES = [
        'CASHIER',
        'REPAIRER',
        'LOGISTICS DISPATCHER',
        'LOGISTICS RIDER',
        'MANAGER',
        'HR',
        'FINANCE',
        'FINANCE STAFF',
        'FINANCE MANAGER',
        'CRM',
        'INVENTORY',
        'INVENTORY MANAGER',
        'PROCUREMENT',
        'PROCUREMENT MANAGER',
        'SHOP OWNER',
        'SUPER ADMIN',
    ];

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('user');

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $roles = collect($user->getRoleNames())
            ->push($user->role)
            ->map(fn (mixed $role): string => $this->normalizeRole($role))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (array_intersect($roles, self::EXCLUDED_ROLES) !== []) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasAnyPermission(self::STAFF_PERMISSIONS)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $businessType = $this->normalizeBusinessType($user->shopOwner?->business_type);

        if (! in_array($businessType, ['retail', 'both'], true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function normalizeRole(mixed $role): string
    {
        return strtoupper(str_replace('_', ' ', trim((string) $role)));
    }

    private function normalizeBusinessType(mixed $businessType): string
    {
        $normalized = strtolower(trim((string) $businessType));

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        return in_array($normalized, ['retail', 'repair'], true) ? $normalized : '';
    }
}
