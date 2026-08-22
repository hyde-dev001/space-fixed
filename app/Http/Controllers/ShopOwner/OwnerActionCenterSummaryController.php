<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Enums\OwnerShellPresentation;
use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterRolloutPolicy;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OwnerActionCenterSummaryController extends Controller
{
    public function __construct(
        private readonly OwnerActionCenterRolloutPolicy $rollout,
        private readonly CanonicalOwnerShellService $shell,
        private readonly OwnerActionCenterService $actionCenter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        if (! $owner instanceof ShopOwner || ! $this->canonicalActionCenterOwner($owner)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            return response()->json([
                'pending_count' => $this->actionCenter
                    ->summaryForHome($owner, 'needs_my_decision')
                    ->total,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('owner_action_center.summary_failed', [
                'shop_id' => (int) $owner->getKey(),
            ]);

            return response()->json(['message' => 'Unavailable.'], 503);
        }
    }

    private function canonicalActionCenterOwner(ShopOwner $owner): bool
    {
        return $this->rollout->select($owner)->selected
            && $this->shell->forOwner($owner)->presentation === OwnerShellPresentation::Canonical;
    }
}
