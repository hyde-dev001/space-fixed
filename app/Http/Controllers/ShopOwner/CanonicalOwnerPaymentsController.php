<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CanonicalOwnerPaymentsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $owner = $request->user('shop_owner');
        if (! $owner instanceof ShopOwner) {
            abort(403);
        }

        return Inertia::render('ERP/cashier/POS');
    }
}
