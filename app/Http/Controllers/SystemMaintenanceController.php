<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SystemMaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceStateService $stateService)
    {
    }

    public function status(Request $request)
    {
        $now = now()->utc();

        try {
            $projection = $this->stateService->publicProjection(
                $this->stateService->resolve($now),
                $now,
            );

            return response()->json($projection)->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return response()->json([
                'state' => 'unavailable',
                'server_time' => $now->toISOString(),
            ], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function page(Request $request): InertiaResponse
    {
        $now = now()->utc();

        try {
            $status = $this->stateService->publicProjection(
                $this->stateService->resolve($now),
                $now,
            );
        } catch (\Throwable) {
            $status = [
                'state' => 'unavailable',
                'server_time' => $now->toISOString(),
            ];
        }

        return Inertia::render('Maintenance', [
            'status' => $status,
            'safe_return_to' => $this->safeReturnDestination($request),
        ]);
    }

    public function safeReturnDestination(Request $request): string
    {
        if (Auth::guard('super_admin')->check() && \Route::has('admin.system-monitoring')) {
            return $this->canonicalPath('admin.system-monitoring');
        }

        if (Auth::guard('shop_owner')->check() && \Route::has('shop-owner.shell.home')) {
            return $this->canonicalPath('shop-owner.shell.home');
        }

        $user = Auth::guard('user')->user();
        if ($user !== null && ! empty($user->shop_owner_id) && \Route::has('erp.time-in')) {
            return $this->canonicalPath($user->force_password_change ? 'erp.profile' : 'erp.time-in');
        }

        return $this->canonicalPath('landing');
    }

    private function canonicalPath(string $routeName): string
    {
        return parse_url(route($routeName), PHP_URL_PATH) ?: '/';
    }
}
