<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RepairRequest;
use App\Services\RepairWarrantyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairWarrantyClaimController extends Controller
{
    public function store(Request $request, int $id, RepairWarrantyService $service)
    {
        $customer = Auth::guard('user')->user();
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'max:100'],
            'reason_details' => ['nullable', 'string', 'max:2000'],
            'same_issue_confirmation' => ['required', 'accepted'],
            'preferred_return_method' => ['required', 'string', 'in:walk_in,customer_delivery,shop_pickup'],
            'preferred_receive_method' => ['nullable', 'string', 'in:walk_in,customer_pickup,shop_delivery'],
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
        ]);

        $repair = RepairRequest::query()->with('shopOwner')->findOrFail($id);
        if ((int) ($repair->user_id ?? 0) !== (int) $customer->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to file a claim for this repair request.',
            ], 403);
        }

        $claim = $service->createCustomerClaim(
            repair: $repair,
            customer: $customer,
            validated: $validated,
            images: (array) $request->file('images', []),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $claim->id,
                'claim_no' => (string) $claim->claim_no,
                'status' => (string) $claim->status,
                'warranty_expires_at_snapshot' => optional($claim->warranty_expires_at_snapshot)->toDateTimeString(),
                'source_channel' => (string) ($claim->source_channel ?? 'customer_portal'),
            ],
        ], 201);
    }

    public function latest(int $id)
    {
        $customer = Auth::guard('user')->user();
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $repair = RepairRequest::query()->findOrFail($id);
        if ((int) ($repair->user_id ?? 0) !== (int) $customer->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to view warranty claims for this repair request.',
            ], 403);
        }

        $latest = $repair->warrantyClaims()->latest('id')->first();

        return response()->json([
            'success' => true,
            'data' => $latest ? [
                'id' => (int) $latest->id,
                'claim_no' => (string) $latest->claim_no,
                'status' => (string) $latest->status,
                'reason_code' => (string) $latest->reason_code,
                'source_channel' => (string) ($latest->source_channel ?? 'customer_portal'),
                'preferred_return_method' => (string) ($latest->preferred_return_method ?? ''),
                'preferred_receive_method' => (string) ($latest->preferred_receive_method ?? ''),
                'created_at' => optional($latest->created_at)->toDateTimeString(),
                'reviewed_at' => optional($latest->reviewed_at)->toDateTimeString(),
                'rejection_reason' => $latest->rejection_reason,
                'approved_repair_request_id' => $latest->approved_repair_request_id,
            ] : null,
        ]);
    }
}
