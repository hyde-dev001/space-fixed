<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ErpRouteCatalog;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpAccessResponder;
use App\Support\Erp\ErpActorContext;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveErpActorContext
{
    public function __construct(
        private readonly ErpRouteCatalog $catalog,
        private readonly ShopModuleAccessService $access,
        private readonly ErpAccessResponder $responder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $entry = is_string($routeName)
            ? $this->catalog->forRoute($request->method(), $routeName)
            : null;

        if (! is_array($entry)) {
            return $this->responder->deny($request, 'ERP_ROUTE_NOT_ALLOWED');
        }

        $guard = $entry['actor_guard'] ?? null;
        if (! is_string($guard) || ! in_array($guard, ['user', 'shop_owner'], true)) {
            return $this->responder->deny($request, 'ERP_ROUTE_NOT_ALLOWED');
        }

        $auth = Auth::guard($guard);
        if (! $auth->check()) {
            return $this->responder->deny($request, 'ERP_AUTH_REQUIRED', [], null, 401);
        }

        $actor = $auth->user();
        if (! $actor instanceof Authenticatable || ! $this->matchesGuard($actor, $guard)) {
            return $this->responder->deny(
                $request,
                'ERP_ROUTE_NOT_ALLOWED',
                $this->stringList($entry['module_keys'] ?? null),
            );
        }

        $ownerMode = $guard === 'shop_owner';
        $tenantOwner = $ownerMode
            ? ($actor instanceof ShopOwner ? $actor : null)
            : ($actor instanceof User ? $this->access->resolveShopOwnerForActor($actor) : null);

        if (! $tenantOwner instanceof ShopOwner) {
            // Preserve legacy employee routes for accounts that predate tenant
            // assignment. Owner routes and employees with a stale tenant ID
            // still fail closed below.
            if (! $ownerMode && $actor instanceof User && $actor->shop_owner_id === null) {
                return $next($request);
            }

            return $this->responder->deny(
                $request,
                'ERP_ROUTE_NOT_ALLOWED',
                $this->stringList($entry['module_keys'] ?? null),
            );
        }

        if ($ownerMode) {
            if (! $this->isApprovedOwnerRoute($tenantOwner, $entry)) {
                return $this->responder->deny(
                    $request,
                    'OWNER_ERP_ACCOUNT_INELIGIBLE',
                    $this->stringList($entry['module_keys'] ?? null),
                );
            }

            if (($entry['owner_access'] ?? null) !== 'allowed') {
                return $this->responder->deny(
                    $request,
                    'ERP_ROUTE_NOT_ALLOWED',
                    $this->stringList($entry['module_keys'] ?? null),
                );
            }
        }

        $context = new ErpActorContext(
            actor: $actor,
            guard: $guard,
            tenantOwner: $tenantOwner,
            ownerMode: $ownerMode,
            routeName: (string) $routeName,
            method: strtoupper($request->method()),
            action: (string) ($entry['action'] ?? 'view'),
            moduleKeys: $this->stringList($entry['module_keys'] ?? null),
            gateMode: is_string($entry['mode'] ?? null) ? $entry['mode'] : null,
        );

        app()->forgetInstance(ErpActorContext::class);
        $request->attributes->set('erp.actor_context', $context);

        return $next($request);
    }

    private function matchesGuard(Authenticatable $actor, string $guard): bool
    {
        return ($guard === 'shop_owner' && $actor instanceof ShopOwner)
            || ($guard === 'user' && $actor instanceof User);
    }

    /**
     * Owner ERP routes normally remain company-only. Existing module metadata
     * explicitly widens the Logistics surface for individual owner-operated
     * shops without changing the owner guard or introducing a new role.
     *
     * @param  array<string, mixed>  $entry
     */
    private function isApprovedOwnerRoute(ShopOwner $owner, array $entry): bool
    {
        $status = $owner->getRawOriginal('status') ?? $owner->status;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
        $registrationType = strtolower(trim((string) $owner->registration_type));
        $registrationTypes = is_array($entry['registration_types'] ?? null)
            ? array_map(static fn (mixed $type): string => strtolower(trim((string) $type)), $entry['registration_types'])
            : ['company'];
        $registrationTypes = $registrationTypes === [] ? ['company'] : $registrationTypes;

        return strtolower(trim($status)) === 'approved'
            && in_array($registrationType, $registrationTypes, true);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
