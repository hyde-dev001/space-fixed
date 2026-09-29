<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerIdentityIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('user')->user();

        if (! $user instanceof User
            || ! $user->isCustomerAccount()
            || ! $user->hasVerifiedEmail()
            || $user->hasApprovedIdentity()) {
            return $next($request);
        }

        $message = 'Identity verification is still under review. You can browse, but transaction access is available after approval.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'code' => 'IDENTITY_VERIFICATION_REQUIRED',
                'message' => $message,
            ], 403);
        }

        return redirect()->route('landing')->with('error', $message);
    }
}
