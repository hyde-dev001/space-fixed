<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AdminPage;
use App\Models\SuperAdmin;
use App\Services\AdminPageAccessService;
use App\Support\PrivilegedFailureResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePrivilegedPageAccess
{
    public function __construct(
        private readonly AdminPageAccessService $access,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function handle(Request $request, Closure $next, string $pageKey): Response
    {
        $admin = $request->user('super_admin');
        abort_unless($admin instanceof SuperAdmin, 401);

        $page = AdminPage::tryFrom($pageKey);
        if (! $page instanceof AdminPage || ! $this->access->allows($admin, $page)) {
            return $this->failures->pageDenied($request, $admin, $pageKey);
        }

        return $next($request);
    }
}
