<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ErpRouteCatalog;
use App\Support\Erp\ErpAccessResponder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureErpAudience
{
    public function __construct(
        private readonly ErpRouteCatalog $catalog,
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

        $requiredGuard = $entry['actor_guard'] ?? null;
        if (! is_string($requiredGuard) || ! in_array($requiredGuard, ['user', 'shop_owner'], true)) {
            return $this->responder->deny(
                $request,
                'ERP_ROUTE_NOT_ALLOWED',
                $this->stringList($entry['module_keys'] ?? null),
            );
        }

        if (Auth::guard($requiredGuard)->check()) {
            return $next($request);
        }

        foreach (['user', 'shop_owner'] as $otherGuard) {
            if ($otherGuard === $requiredGuard || ! Auth::guard($otherGuard)->check()) {
                continue;
            }

            return $this->responder->deny(
                $request,
                'ERP_ROUTE_NOT_ALLOWED',
                $this->stringList($entry['module_keys'] ?? null),
            );
        }

        return $next($request);
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
