<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\ErpWorkspaceNavigationService;
use App\Services\OwnerShell\CanonicalOwnerDashboardService;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpAccessResponder;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ShopModuleAccessService $moduleAccess,
        private readonly ErpWorkspaceNavigationService $navigation,
        private readonly CanonicalOwnerDashboardService $dashboard,
        private readonly ErpAccessResponder $responder,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('shop-owner.shell.home');
    }

    public function module(Request $request, ?string $module = null): InertiaResponse|RedirectResponse
    {
        $module ??= $request->route('module');
        if (! is_string($module) || $module === '') {
            abort(404);
        }

        $context = $request->attributes->get('erp.actor_context');
        if (! $context instanceof ErpActorContext) {
            abort(500);
        }

        $activeModule = $this->navigation->forSlugForOwner($context->tenantOwner(), $module);
        if ($activeModule === null) {
            abort(404);
        }

        $decision = $this->moduleAccess->decide(
            $context->tenantOwner(),
            $activeModule['key'],
            (bool) config('shop_modules.enforcement_enabled', false),
        );
        if (! $decision->allowed) {
            if ($request->routeIs('shop-owner.erp.module')) {
                return redirect()->route('shop-owner.shell.settings.modules-team', [
                    'module' => $activeModule['key'],
                ]);
            }

            return $this->responder->deny(
                $request,
                $decision->code ?? 'ERP_ROUTE_NOT_ALLOWED',
                $decision->moduleKeys,
                $decision->message,
            );
        }

        $request->attributes->set('erp.active_module', $activeModule);

        if ($request->routeIs('shop-owner.erp.module')) {
            return redirect($activeModule['overview']['url']);
        }

        return $this->dashboard->render(
            $activeModule['key'],
            (int) $context->tenantOwner()->getKey(),
            $activeModule,
        );
    }

}
