<?php

namespace App\Http\Middleware;

use App\Data\ShopModuleAccessDecision;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ShopModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureShopModuleEnabled
{
    public function __construct(
        private readonly ShopModuleAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('shop_modules.enforcement_enabled', false)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $entry = is_string($routeName) ? config("shop_modules.routes.{$routeName}") : null;
        if (! is_array($entry) || ($entry['classification'] ?? null) !== 'module') {
            return $this->deny($request, ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: [],
                message: 'This shop route is not available under the current module policy.',
            ), null);
        }

        $moduleKeys = $this->stringList($entry['module_keys'] ?? null);
        $mode = $entry['mode'] ?? null;
        $guards = $this->stringList($entry['actor_guards'] ?? null);
        if ($moduleKeys === [] || ! is_string($mode) || ! in_array($mode, config('shop_modules.supported_gate_modes', []), true)
            || $guards === [] || array_diff($guards, ['shop_owner', 'user']) !== []) {
            return $this->deny($request, ShopModuleAccessDecision::deny(
                code: 'UNKNOWN_MODULE',
                moduleKeys: $moduleKeys,
                message: 'This shop module policy is not available.',
            ), $guards[0] ?? null);
        }

        $contexts = $this->authenticatedContexts($guards);
        if ($contexts === []) {
            // Authentication middleware is responsible for anonymous requests.
            return $next($request);
        }

        $ownerIds = collect($contexts)
            ->pluck('owner')
            ->filter(fn (?ShopOwner $owner): bool => $owner !== null)
            ->map(fn (ShopOwner $owner): int => (int) $owner->id)
            ->unique()
            ->values();
        if ($ownerIds->count() > 1) {
            return $this->deny($request, ShopModuleAccessDecision::deny(
                code: 'MODULE_INELIGIBLE',
                moduleKeys: $moduleKeys,
                message: 'The authenticated shop context is inconsistent.',
            ), $contexts[0]['guard']);
        }

        $selected = $contexts[0];
        $owner = $selected['owner'];
        if (! $owner instanceof ShopOwner) {
            if (($entry['customer_capable'] ?? false) === true && $selected['actor'] instanceof User && ! $selected['actor']->shop_owner_id) {
                return $next($request);
            }

            return $this->deny($request, ShopModuleAccessDecision::deny(
                code: 'MODULE_INELIGIBLE',
                moduleKeys: $moduleKeys,
                message: 'This account is not associated with an eligible shop.',
            ), $selected['guard']);
        }

        $decision = $this->access->decideGate($owner, $mode, $moduleKeys);
        if ($decision->allowed) {
            return $next($request);
        }

        return $this->deny($request, $decision, $selected['guard']);
    }

    /**
     * @param  array<int, string>  $guards
     * @return array<int, array{guard: string, actor: object, owner: ?ShopOwner}>
     */
    private function authenticatedContexts(array $guards): array
    {
        $contexts = [];
        foreach ($guards as $guard) {
            $auth = Auth::guard($guard);
            if (! $auth->check()) {
                continue;
            }

            $actor = $auth->user();
            if (! is_object($actor)) {
                continue;
            }

            $owner = $actor instanceof ShopOwner
                ? $actor
                : ($actor instanceof User ? $this->access->resolveShopOwnerForActor($actor) : null);
            $contexts[] = ['guard' => $guard, 'actor' => $actor, 'owner' => $owner];
        }

        return $contexts;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
    }

    private function deny(Request $request, ShopModuleAccessDecision $decision, ?string $guard): Response
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'message' => $decision->message,
                'code' => $decision->code ?? 'UNKNOWN_MODULE',
                'module_keys' => $decision->moduleKeys,
            ], 403);
        }

        $target = $guard === 'shop_owner'
            ? route('shop-owner.settings')
            : url('/erp/staff/dashboard');

        return redirect($target)->with('error', $decision->message);
    }
}
