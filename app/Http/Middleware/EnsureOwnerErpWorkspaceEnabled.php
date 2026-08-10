<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOwnerErpWorkspaceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('shop_modules.owner_erp_workspace_enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
