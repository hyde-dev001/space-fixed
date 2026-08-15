<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\ErpRouteCatalog;
use App\Services\ShopModuleAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

final class CanonicalOwnerPaymentsController extends Controller
{
    public function __construct(
        private readonly ShopModuleAccessService $moduleAccess,
        private readonly ErpRouteCatalog $catalog,
    ) {}

    public function __invoke(Request $request): Response
    {
        $owner = $request->user('shop_owner');
        if (! $owner instanceof ShopOwner) {
            abort(403);
        }

        $states = $this->moduleAccess->statesFor($owner);
        $links = [
            'retail' => null,
            'repair' => null,
        ];
        $retailUrl = $this->sourceRoute('shop-owner.erp.retail.point-of-sale', 'shop-owner.point-of-sale');
        $repairUrl = $this->sourceRoute('shop-owner.erp.repair.point-of-sale', 'shop-owner.erp.repair.point-of-sale');

        if (($states['retail_operations']['accessible'] ?? false) === true
            && $retailUrl !== null) {
            $links['retail'] = $retailUrl;
        }

        if (($states['repair_operations']['accessible'] ?? false) === true
            && (bool) config('shop_modules.owner_erp_workspace_enabled', false)
            && $repairUrl !== null) {
            $links['repair'] = $repairUrl;
        }

        return Inertia::render('ShopOwner/Payments/CanonicalPaymentsLanding', [
            'links' => $links,
        ]);
    }

    private function sourceRoute(string $capabilityRouteName, string $targetRouteName): ?string
    {
        $entry = $this->catalog->entry($capabilityRouteName);

        if (! Route::has($targetRouteName) || ! is_array($entry)) {
            return null;
        }

        if (! in_array('GET', $entry['methods'] ?? [], true)
            || ($entry['audience'] ?? null) !== 'shop_owner'
            || ($entry['actor_guard'] ?? null) !== 'shop_owner'
            || ($entry['owner_access'] ?? null) !== 'allowed') {
            return null;
        }

        return route($targetRouteName);
    }
}
