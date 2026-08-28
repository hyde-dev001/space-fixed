<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Erp\ErpAccessResponder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCompanyOwnerProductMutations
{
    public function __construct(private readonly ErpAccessResponder $accessResponder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if ($shopOwner?->isCompany()) {
            return $this->accessResponder->deny(
                $request,
                'ERP_ROUTE_NOT_ALLOWED',
                ['retail_operations'],
                'Company product creation and changes must be handled through inventory by authorized staff.',
            );
        }

        return $next($request);
    }
}
