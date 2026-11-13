<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RetailWarrantyIssuance;
use App\Models\ShopOwner;
use App\Services\RetailWarrantyCertificateService;
use App\Services\ShopModuleAccessService;
use Illuminate\Http\Request;

class StaffRetailWarrantyController extends Controller
{
    public function certificate(Request $request, string $reference, RetailWarrantyCertificateService $certificates)
    {
        $staff = $request->user('user');
        abort_unless($staff && $staff->isEmployeeAccount() && $staff->shop_owner_id && ($staff->can('access-staff-job-orders') || $staff->can('access-unified-pos')), 403);
        $shop = ShopOwner::findOrFail($staff->shop_owner_id);
        abort_unless(app(ShopModuleAccessService::class)->canAccess($shop, 'retail_operations'), 403);

        return $certificates->download(RetailWarrantyIssuance::where('shop_owner_id', $shop->id)->where('warranty_number', $reference)->firstOrFail());
    }
}
