<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Enums\OwnerShellPresentation;
use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterRolloutPolicy;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class ShopOwnerDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerActionCenterRolloutPolicy $rollout,
        private readonly CanonicalOwnerShellService $shell,
        private readonly OwnerActionCenterService $actionCenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $props = [
            'shop_owner' => $request->user('shop_owner'),
            'showPhaseThreePlaceholders' => (bool) $request->route('canonical_home', false),
        ];

        $owner = $request->user('shop_owner');
        if ((bool) $request->route('canonical_home', false) && $owner instanceof ShopOwner) {
            try {
                $selection = $this->rollout->select($owner);
                $metadata = $this->shell->forOwner($owner);

                if ($selection->selected && $metadata->presentation === OwnerShellPresentation::Canonical) {
                    $props['ownerActionCenter'] = $this->actionCenter->summaryForHome($owner)->toArray();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return Inertia::render('ShopOwner/Dashboard', $props);
    }
}
