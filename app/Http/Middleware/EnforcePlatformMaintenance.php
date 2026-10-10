<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceStateService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnforcePlatformMaintenance
{
    public function __construct(private readonly MaintenanceStateService $stateService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isBypassed($request)) {
            return $next($request);
        }

        $now = now()->utc();

        try {
            $snapshot = $this->stateService->resolve($now);
        } catch (\Throwable) {
            return response()->json([
                'code' => 'MAINTENANCE_UNAVAILABLE',
                'state' => 'unavailable',
                'message' => 'The platform status is temporarily unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Cache-Control', 'no-store');
        }

        $projection = $this->stateService->publicProjection($snapshot, $now);

        if (($snapshot['state'] ?? 'operational') === 'active') {
            return $this->activeResponse($request, $projection, $now);
        }

        if ($this->stateService->isFreezeActive($snapshot, $now)
            && $this->isCriticalRoute($request)) {
            return response()->json(array_merge([
                'code' => 'MAINTENANCE_FREEZE_ACTIVE',
                'message' => 'This operation cannot start during the maintenance freeze.',
            ], $projection), Response::HTTP_CONFLICT);
        }

        return $next($request);
    }

    private function activeResponse(Request $request, array $projection, Carbon $now): Response
    {
        $headers = ['X-SoleSpace-Maintenance' => 'active'];
        if (! empty($projection['ends_at'])) {
            $retryAfter = max(1, $now->diffInSeconds(Carbon::parse($projection['ends_at']), false));
            $headers['Retry-After'] = (string) $retryAfter;
        }

        if ($request->expectsJson() || $request->isJson() || $request->is('api/*') || ! $request->isMethodSafe()) {
            return response()->json(array_merge([
                'code' => 'MAINTENANCE_ACTIVE',
                'message' => $projection['message'] ?? 'SoleSpace is temporarily unavailable.',
            ], $projection), Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        $response = Inertia::render('Maintenance', [
            'status' => $projection,
            'safe_return_to' => app(\App\Http\Controllers\SystemMaintenanceController::class)
                ->safeReturnDestination($request),
        ])->toResponse($request);
        $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function isBypassed(Request $request): bool
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if (is_string($name) && in_array($name, config('platform_maintenance.bypass_route_names', []), true)) {
            return true;
        }

        foreach (config('platform_maintenance.bypass_route_prefixes', []) as $prefix) {
            if (is_string($name) && str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return in_array($request->path(), config('platform_maintenance.bypass_paths', []), true);
    }

    private function isCriticalRoute(Request $request): bool
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        return is_string($name)
            && in_array($name, config('platform_maintenance.critical_route_names', []), true);
    }
}
