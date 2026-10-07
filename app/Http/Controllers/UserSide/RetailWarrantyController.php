<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\RetailWarrantyIssuance;
use App\Services\RetailWarrantyCertificateService;
use App\Services\RetailWarrantyService;
use Illuminate\Http\Request;

class RetailWarrantyController extends Controller
{
    private function owned(Request $request, string $reference): RetailWarrantyIssuance
    {
        $buyer = $request->user('user');
        abort_if(! $buyer || $buyer->isEmployeeAccount(), 403);

        return RetailWarrantyIssuance::where('customer_id', $buyer->id)->where('warranty_number', $reference)->with('warranties')->firstOrFail();
    }

    public function show(Request $request, string $reference, RetailWarrantyService $warranties)
    {
        $issuance = $this->owned($request, $reference);

        return response()->json(['data' => $warranties->projectIssuances(new \Illuminate\Database\Eloquent\Collection([$issuance]))[$issuance->order_id]]);
    }

    public function certificate(Request $request, string $reference, RetailWarrantyCertificateService $certificates)
    {
        return $certificates->download($this->owned($request, $reference));
    }
}
