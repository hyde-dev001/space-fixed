<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RepairWarrantyClaim;
use App\Services\RepairWarrantyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairerWarrantyClaimController extends Controller
{
    public function index(Request $request)
    {
        $actorContext = $this->resolveActorContext();
        if (!$actorContext['authenticated']) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $shopOwnerId = (int) $actorContext['shop_owner_id'];
        if ($shopOwnerId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Shop scope could not be resolved.',
            ], 422);
        }

        $status = trim((string) $request->query('status', ''));
        $actorUserId = (int) $actorContext['actor_user_id'];
        $canViewAll = (bool) $actorContext['can_view_all'];
        $isUserGuard = (bool) $actorContext['is_user_guard'];

        $claims = RepairWarrantyClaim::query()
            ->with([
                'originalRepair:id,request_id,customer_name,status,assigned_repairer_id',
                'approvedRepair:id,request_id',
                'repairHandler:id,name,email',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->when($status !== '' && strtolower($status) !== 'all', fn ($query) => $query->where('status', $status))
            ->when($isUserGuard && !$canViewAll, function ($query) use ($actorUserId) {
                $query->where(function ($inner) use ($actorUserId) {
                    $inner->where('repair_handler_user_id', $actorUserId)
                        ->orWhereHas('originalRepair', function ($repairQuery) use ($actorUserId) {
                            $repairQuery->where('assigned_repairer_id', $actorUserId);
                        });
                });
            })
            ->orderByDesc('id')
            ->get();

        $data = $claims->map(function (RepairWarrantyClaim $claim) {
            return [
                'id' => (int) $claim->id,
                'claim_no' => (string) $claim->claim_no,
                'status' => (string) $claim->status,
                'reason_code' => (string) $claim->reason_code,
                'reason_details' => $claim->reason_details,
                'evidence_media' => is_array($claim->evidence_media) ? $claim->evidence_media : [],
                'preferred_return_method' => $claim->preferred_return_method,
                'source_channel' => $claim->source_channel,
                'handler_source' => $claim->handler_source,
                'repair_handler_user_id' => $claim->repair_handler_user_id,
                'repair_handler_name' => $claim->repairHandler?->name,
                'original_repair' => [
                    'id' => (int) ($claim->originalRepair?->id ?? 0),
                    'request_id' => (string) ($claim->originalRepair?->request_id ?? ''),
                    'customer_name' => (string) ($claim->originalRepair?->customer_name ?? ''),
                    'status' => (string) ($claim->originalRepair?->status ?? ''),
                ],
                'approved_repair_request_id' => $claim->approved_repair_request_id,
                'warranty_expires_at_snapshot' => optional($claim->warranty_expires_at_snapshot)->toDateTimeString(),
                'created_at' => optional($claim->created_at)->toDateTimeString(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function metrics(Request $request, RepairWarrantyService $service)
    {
        $actorContext = $this->resolveActorContext();
        if (!$actorContext['authenticated']) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        $shopOwnerId = (int) $actorContext['shop_owner_id'];
        if ($shopOwnerId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Shop scope could not be resolved.',
            ], 422);
        }

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));

        $canViewAll = (bool) $actorContext['can_view_all'];
        $isUserGuard = (bool) $actorContext['is_user_guard'];
        $actorUserId = (int) $actorContext['actor_user_id'];

        $data = $service->getKpiSummary(
            shopOwnerId: $shopOwnerId,
            actorUserId: $isUserGuard ? $actorUserId : null,
            restrictToActor: $isUserGuard ? !$canViewAll : false,
            days: $days
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function approve(int $claim, RepairWarrantyService $service)
    {
        $actorContext = $this->resolveActorContext();
        if (!$actorContext['authenticated']) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $warrantyClaim = RepairWarrantyClaim::query()->findOrFail($claim);
        if ((int) $actorContext['shop_owner_id'] !== (int) $warrantyClaim->shop_owner_id) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to approve this claim.'], 403);
        }

        if ((bool) $actorContext['is_user_guard'] && !$this->canActOnClaim($actorContext['actor'], $warrantyClaim)) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to approve this claim.'], 403);
        }

        $updated = $service->approveClaim($warrantyClaim, (int) $actorContext['actor_user_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $updated->id,
                'status' => (string) $updated->status,
                'approved_repair_request_id' => $updated->approved_repair_request_id,
                'reviewed_at' => optional($updated->reviewed_at)->toDateTimeString(),
            ],
        ]);
    }

    public function reject(Request $request, int $claim, RepairWarrantyService $service)
    {
        $actorContext = $this->resolveActorContext();
        if (!$actorContext['authenticated']) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $warrantyClaim = RepairWarrantyClaim::query()->findOrFail($claim);
        if ((int) $actorContext['shop_owner_id'] !== (int) $warrantyClaim->shop_owner_id) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to reject this claim.'], 403);
        }

        if ((bool) $actorContext['is_user_guard'] && !$this->canActOnClaim($actorContext['actor'], $warrantyClaim)) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to reject this claim.'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $updated = $service->rejectClaim(
            $warrantyClaim,
            (int) $actorContext['actor_user_id'],
            (string) $validated['rejection_reason']
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $updated->id,
                'status' => (string) $updated->status,
                'rejection_reason' => (string) ($updated->rejection_reason ?? ''),
                'reviewed_at' => optional($updated->reviewed_at)->toDateTimeString(),
            ],
        ]);
    }

    private function canViewAllClaims(object $actor): bool
    {
        if (method_exists($actor, 'can') && $actor->can('access-repair-job-orders')) {
            return true;
        }

        if (method_exists($actor, 'hasRole')) {
            return $actor->hasRole('Manager')
                || $actor->hasRole('manager')
                || $actor->hasRole('Shop Owner')
                || $actor->hasRole('shop owner');
        }

        return false;
    }

    private function canActOnClaim(object $actor, RepairWarrantyClaim $claim): bool
    {
        $actorShopOwnerId = (int) ($actor->shop_owner_id ?? 0);
        if ($actorShopOwnerId <= 0 || $actorShopOwnerId !== (int) $claim->shop_owner_id) {
            return false;
        }

        if ($this->canViewAllClaims($actor)) {
            return true;
        }

        $actorId = (int) ($actor->id ?? 0);
        if ($actorId <= 0) {
            return false;
        }

        if ((int) ($claim->repair_handler_user_id ?? 0) === $actorId) {
            return true;
        }

        return RepairWarrantyClaim::query()
            ->whereKey($claim->id)
            ->whereHas('originalRepair', function ($repairQuery) use ($actorId) {
                $repairQuery->where('assigned_repairer_id', $actorId);
            })
            ->exists();
    }

    /**
     * @return array{
     *   authenticated: bool,
     *   is_user_guard: bool,
     *   actor: object|null,
     *   shop_owner_id: int,
     *   actor_user_id: int,
     *   can_view_all: bool
     * }
     */
    private function resolveActorContext(): array
    {
        $userActor = Auth::guard('user')->user();
        if ($userActor) {
            $shopOwnerId = (int) ($userActor->shop_owner_id ?? 0);

            return [
                'authenticated' => true,
                'is_user_guard' => true,
                'actor' => $userActor,
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => (int) ($userActor->id ?? 0),
                'can_view_all' => $this->canViewAllClaims($userActor),
            ];
        }

        $shopOwnerActor = Auth::guard('shop_owner')->user();
        if ($shopOwnerActor) {
            return [
                'authenticated' => true,
                'is_user_guard' => false,
                'actor' => $shopOwnerActor,
                'shop_owner_id' => (int) ($shopOwnerActor->id ?? 0),
                'actor_user_id' => 0,
                'can_view_all' => true,
            ];
        }

        return [
            'authenticated' => false,
            'is_user_guard' => false,
            'actor' => null,
            'shop_owner_id' => 0,
            'actor_user_id' => 0,
            'can_view_all' => false,
        ];
    }
}
