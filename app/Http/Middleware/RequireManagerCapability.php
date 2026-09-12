<?php

namespace App\Http\Middleware;

use App\Services\Manager\ManagerAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireManagerCapability
{
    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
    ) {
    }

    /**
     * @param  Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return $this->denyUnauthenticated($request);
        }

        if (! $this->authorization->allows($user, $capability)) {
            return $this->denyForbidden($request, $capability);
        }

        $request->attributes->set('manager.shop_owner_id', $this->authorization->shopOwnerId($user));
        $request->attributes->set('manager.user', $user);

        return $next($request);
    }

    private function denyUnauthenticated(Request $request): Response
    {
        if ($request->header('X-Inertia')) {
            return redirect()->route('landing')->with('error', 'Please login to continue');
        }

        return response()->json([
            'message' => 'Unauthenticated',
            'error' => 'UNAUTHENTICATED',
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function denyForbidden(Request $request, string $capability): Response
    {
        if ($request->header('X-Inertia')) {
            return redirect()->route('landing')->with('error', 'You do not have permission to access this page');
        }

        return response()->json([
            'message' => 'You do not have permission to access this Manager capability.',
            'error' => 'INSUFFICIENT_MANAGER_CAPABILITY',
            'capability' => $capability,
        ], Response::HTTP_FORBIDDEN);
    }
}
