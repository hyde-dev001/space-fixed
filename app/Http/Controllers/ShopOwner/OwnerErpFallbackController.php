<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Requests\ShopOwner\OpenOwnerErpFallbackRequest;
use App\Models\ShopOwner;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

final class OwnerErpFallbackController
{
    public function __construct(
        private readonly CanonicalOwnerShellService $ownerShell,
    ) {}

    public function __invoke(OpenOwnerErpFallbackRequest $request): RedirectResponse
    {
        $owner = $request->user('shop_owner');
        if (! $owner instanceof ShopOwner) {
            abort(403);
        }

        if (! $this->ownerShell->ownerErpFallbackAllowed($owner)) {
            abort(404);
        }

        $validated = $request->validated();
        Log::info('shop_owner_erp_fallback_used', [
            'shop_id' => $owner->getKey(),
            'reason' => $validated['reason'],
            'source' => $validated['source'],
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
        ]);

        return redirect()->route('shop-owner.erp.workspace');
    }
}
