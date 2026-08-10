<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\ErpWorkspaceNavigationService;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpAccessResponder;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ShopModuleAccessService $moduleAccess,
        private readonly ErpWorkspaceNavigationService $navigation,
        private readonly ErpAccessResponder $responder,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('ERP/Workspace', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function module(Request $request, string $module): InertiaResponse|\Symfony\Component\HttpFoundation\Response
    {
        $context = $request->attributes->get('erp.actor_context');
        if (! $context instanceof ErpActorContext) {
            abort(500);
        }

        $activeModule = $this->navigation->forSlug($module);
        if ($activeModule === null) {
            abort(404);
        }

        $decision = $this->moduleAccess->decide(
            $context->tenantOwner(),
            $activeModule['key'],
            (bool) config('shop_modules.enforcement_enabled', false),
        );
        if (! $decision->allowed) {
            return $this->responder->deny(
                $request,
                $decision->code ?? 'ERP_ROUTE_NOT_ALLOWED',
                $decision->moduleKeys,
                $decision->message,
            );
        }

        $request->attributes->set('erp.active_module', $activeModule);

        return Inertia::render('ERP/ModuleLanding', [
            'tenantOwnerId' => $context->tenantOwner()->getKey(),
            'activeModule' => $activeModule,
            'navigationMode' => 'module',
            'urls' => $this->urls(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $context = $request->attributes->get('erp.actor_context');
        if (! $context instanceof ErpActorContext) {
            abort(500);
        }

        $owner = $context->tenantOwner();
        $states = $this->moduleAccess->statesFor($owner);
        $modules = config('shop_modules.modules', []);
        $enabledModules = [];
        $unavailableModules = [];

        foreach ($modules as $moduleKey => $module) {
            $state = $states[$moduleKey] ?? [
                'eligible' => false,
                'enabled' => false,
                'accessible' => false,
                'code' => 'MODULE_STATE_MISSING',
                'reason' => 'This module has not been initialized for the shop.',
            ];
            $item = [
                'key' => $moduleKey,
                'label' => $module['label'] ?? $moduleKey,
                'url' => $this->moduleEntryUrl($moduleKey),
                'eligible' => (bool) ($state['eligible'] ?? false),
                'enabled' => (bool) ($state['enabled'] ?? false),
                'accessible' => (bool) ($state['accessible'] ?? false),
                'code' => $state['code'] ?? null,
                'reason' => $state['reason'] ?? null,
            ];

            if ($item['accessible']) {
                $enabledModules[] = $item;
            } else {
                $unavailableModules[] = $item;
            }
        }

        return [
            'tenantOwnerId' => $owner->getKey(),
            'workspaceEnabled' => (bool) config('shop_modules.owner_erp_workspace_enabled', false),
            'activeModule' => null,
            'navigationMode' => 'picker',
            'enabledModules' => $enabledModules,
            'unavailableModules' => $unavailableModules,
            'navigationGroups' => [
                [
                    'key' => 'workspace',
                    'label' => 'ERP Workspace',
                    'pages' => [
                        [
                            'label' => 'Overview',
                            'routeName' => 'shop-owner.erp.workspace',
                            'url' => route('shop-owner.erp.workspace'),
                        ],
                    ],
                ],
            ],
            'urls' => [
                'portal' => route('shop-owner.dashboard'),
                'settings' => route('shop-owner.settings'),
                'workspace' => route('shop-owner.erp.workspace'),
            ],
        ];
    }

    private function moduleEntryUrl(string $moduleKey): ?string
    {
        return $this->navigation->urlForKey($moduleKey);
    }

    /**
     * @return array{portal: string, settings: string, workspace: string}
     */
    private function urls(): array
    {
        return [
            'portal' => route('shop-owner.dashboard'),
            'settings' => route('shop-owner.settings'),
            'workspace' => route('shop-owner.erp.workspace'),
        ];
    }
}
