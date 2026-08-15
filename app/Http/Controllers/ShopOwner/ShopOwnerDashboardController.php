<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ShopOwnerDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('ShopOwner/Dashboard', [
            'shop_owner' => $request->user('shop_owner'),
            'showPhaseThreePlaceholders' => (bool) $request->route('canonical_home', false),
        ]);
    }
}
