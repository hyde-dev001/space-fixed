<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\ShopModuleAccessService;
use App\Support\Erp\ErpActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ShopModuleAccessService $moduleAccess,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('ERP/Workspace', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
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
}
