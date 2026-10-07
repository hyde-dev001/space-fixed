<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\RetailWarranty;
use App\Models\RetailWarrantyIssuance;
use App\Models\ShopOwner;
use App\Services\RetailWarrantyCertificateService;
use App\Services\RetailWarrantyService;
use App\Services\ShopModuleAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RetailWarrantyController extends Controller
{
    private function owner(Request $request): ShopOwner
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner && app(ShopModuleAccessService::class)->canAccess($owner, 'retail_operations'), 403);

        return $owner;
    }

    public function index(Request $request, RetailWarrantyService $warranties)
    {
        $owner = $this->owner($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['active', 'partially_used', 'no_remaining_coverage', 'expired', 'voided'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = RetailWarrantyIssuance::where('shop_owner_id', $owner->id)->with('warranties');
        if ($search = trim($input['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('warranty_number', 'like', '%'.$search.'%')
                ->orWhere('order_snapshot->number', 'like', '%'.$search.'%')->orWhere('customer_snapshot->name', 'like', '%'.$search.'%'));
        }
        if ($input['status'] ?? null) {
            $warranties->filterIssuances($query, $input['status']);
        }
        $page = $query->orderByDesc('issued_at')->orderByDesc('id')->paginate($input['per_page'] ?? 15);
        $projections = $warranties->projectIssuances($page->getCollection(), 'owner');
        $page->setCollection($page->getCollection()->map(fn ($issuance) => $projections[$issuance->order_id]));

        return response()->json($page);
    }

    public function show(Request $request, string $reference, RetailWarrantyService $warranties)
    {
        $issuance = RetailWarrantyIssuance::where('shop_owner_id', $this->owner($request)->id)->where('warranty_number', $reference)->firstOrFail();

        return response()->json(['data' => $warranties->projectIssuances(new \Illuminate\Database\Eloquent\Collection([$issuance]), 'owner')[$issuance->order_id]]);
    }

    public function certificate(Request $request, string $reference, RetailWarrantyCertificateService $certificates)
    {
        $issuance = RetailWarrantyIssuance::where('shop_owner_id', $this->owner($request)->id)->where('warranty_number', $reference)->firstOrFail();

        return $certificates->download($issuance);
    }

    public function voidItem(Request $request, int $warrantyId, RetailWarrantyService $warranties)
    {
        $owner = $this->owner($request);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $warranty = RetailWarranty::where('shop_owner_id', $owner->id)->findOrFail($warrantyId);
        $warranties->voidWarranty($warranty, $owner, $input['reason']);

        return response()->json(['message' => 'Warranty coverage voided. Original certificate and terms are preserved.']);
    }

    public function staffCertificate(Request $request, string $reference, RetailWarrantyCertificateService $certificates)
    {
        $staff = $request->user('user');
        abort_unless($staff && $staff->isEmployeeAccount() && $staff->shop_owner_id && ($staff->can('access-staff-job-orders') || $staff->can('access-unified-pos')), 403);
        $shop = ShopOwner::findOrFail($staff->shop_owner_id);
        abort_unless(app(ShopModuleAccessService::class)->canAccess($shop, 'retail_operations'), 403);

        return $certificates->download(RetailWarrantyIssuance::where('shop_owner_id', $shop->id)->where('warranty_number', $reference)->firstOrFail());
    }
}
