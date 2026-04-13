<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RepairWarrantyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairPosWarrantyClaimController extends Controller
{
    public function store(Request $request, RepairWarrantyService $service)
    {
        $actor = $this->resolveActor();
        if (!$actor) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $validated = $request->validate([
            'repair_request_id' => ['required', 'integer', 'exists:repair_requests,id'],
            'receipt_no' => ['required', 'string', 'max:120'],
            'walk_in_phone' => ['required', 'string', 'max:30'],
            'reason_code' => ['required', 'string', 'max:100'],
            'reason_details' => ['nullable', 'string', 'max:2000'],
            'same_issue_confirmation' => ['required', 'accepted'],
            'preferred_return_method' => ['required', 'string', 'in:walk_in,customer_delivery'],
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:20480'],
        ]);

        $actorShopOwnerId = $this->resolveActorShopOwnerId($actor);
        if ($actorShopOwnerId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Shop scope could not be resolved for this actor.',
            ], 422);
        }

        $repair = RepairRequest::query()->with('shopOwner')->findOrFail((int) $validated['repair_request_id']);
        if ((int) ($repair->shop_owner_id ?? 0) !== $actorShopOwnerId) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to file a warranty claim for this repair request.',
            ], 403);
        }

        if (!$this->isManualPosRepair($repair)) {
            return response()->json([
                'success' => false,
                'message' => 'POS warranty claim filing is available only for manual walk-in POS repairs.',
            ], 422);
        }

        $receiptNo = trim((string) $validated['receipt_no']);
        $sourceTx = PosTransaction::query()
            ->where('module_type', 'repair')
            ->where('module_reference_id', $repair->id)
            ->whereHas('receipt', function ($query) use ($receiptNo) {
                $query->where('receipt_no', $receiptNo);
            })
            ->first();

        if (!$sourceTx) {
            return response()->json([
                'success' => false,
                'message' => 'Presented receipt does not match this repair request.',
            ], 422);
        }

        $providedPhone = $this->normalizePhone((string) $validated['walk_in_phone']);
        $repairPhone = $this->normalizePhone((string) ($repair->phone ?? ''));
        $txPhone = $this->normalizePhone((string) ($sourceTx->walk_in_phone ?? ''));

        if ($providedPhone === '' || ($providedPhone !== $repairPhone && $providedPhone !== $txPhone)) {
            return response()->json([
                'success' => false,
                'message' => 'Walk-in contact number does not match the original POS repair record.',
            ], 422);
        }

        $claim = $service->createPosWalkInClaim(
            repair: $repair,
            validated: $validated,
            images: (array) $request->file('images', []),
            actorId: $this->resolveActorAuditUserId(),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $claim->id,
                'claim_no' => (string) $claim->claim_no,
                'status' => (string) $claim->status,
                'source_channel' => (string) ($claim->source_channel ?? 'manual_pos_walk_in'),
                'warranty_expires_at_snapshot' => optional($claim->warranty_expires_at_snapshot)->toDateTimeString(),
            ],
        ], 201);
    }

    private function isManualPosRepair(RepairRequest $repair): bool
    {
        if (str_starts_with((string) ($repair->request_id ?? ''), 'REP-POS-')) {
            return true;
        }

        $pricingMode = strtolower((string) (is_array($repair->pricing_breakdown) ? ($repair->pricing_breakdown['mode'] ?? '') : ''));

        return $pricingMode === 'manual_pos';
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?: '';
    }

    private function resolveActor(): ?object
    {
        return Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();
    }

    private function resolveActorShopOwnerId(?object $actor): int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->user()?->shop_owner_id ?? 0);
        }

        return (int) ($actor?->shop_owner_id ?? 0);
    }

    private function resolveActorAuditUserId(): int
    {
        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->id() ?? 0);
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return 0;
        }

        $shopOwnerId = (int) ($shopOwner->id ?? 0);
        if ($shopOwnerId <= 0) {
            return 0;
        }

        $shopOwnerEmail = trim((string) ($shopOwner->email ?? ''));
        if ($shopOwnerEmail !== '') {
            $matchedByEmail = (int) (User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('email', $shopOwnerEmail)
                ->value('id') ?? 0);

            if ($matchedByEmail > 0) {
                return $matchedByEmail;
            }
        }

        return (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->value('id') ?? 0);
    }
}
