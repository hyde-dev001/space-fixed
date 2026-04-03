<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosRefund;
use App\Services\RepairOnlineRefundWorkflowService;
use App\Services\RepairPosRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairRefundWorkflowController extends Controller
{
    public function repairerQueue(Request $request)
    {
        $actor = Auth::guard('user')->user();

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->where('shop_owner_id', (int) ($actor->shop_owner_id ?? 0))
            ->where('repairer_status', 'pending')
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    public function repairerApprove(Request $request, PosRefund $refund, RepairOnlineRefundWorkflowService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'assessment_note' => ['required', 'string', 'max:2000'],
            'approved_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $updated = $service->repairerApprove(
            refund: $refund,
            actorId: (int) $actor->id,
            assessmentNote: (string) $validated['assessment_note'],
            approvedAmount: (float) $validated['approved_amount'],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function repairerReject(Request $request, PosRefund $refund, RepairOnlineRefundWorkflowService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'assessment_note' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->repairerReject(
            refund: $refund,
            actorId: (int) $actor->id,
            assessmentNote: (string) $validated['assessment_note'],
            reason: (string) $validated['reason'],
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeApprove(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: (int) $actor->id,
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
            stage: 'finance',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeReject(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->reject(
            refund: $refund,
            actorId: (int) $actor->id,
            rejectionReason: (string) $validated['reason'],
            stage: 'finance',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function financeExecute(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('user')->user();

        if (!$this->canFinanceExecute($actor, $refund)) {
            abort(403, 'Not authorized to execute this refund.');
        }

        $validated = $request->validate([
            'execution_mode' => ['nullable', 'in:manual,gateway'],
            'execution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->execute(
            refund: $refund,
            actorId: (int) $actor->id,
            executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
            executionNote: $validated['execution_note'] ?? null,
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function ownerApprove(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: (int) ($actor->id ?? 0),
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
            stage: 'shop_owner',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function ownerReject(Request $request, PosRefund $refund, RepairPosRefundService $service)
    {
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $service->reject(
            refund: $refund,
            actorId: (int) ($actor->id ?? 0),
            rejectionReason: (string) $validated['reason'],
            stage: 'shop_owner',
        );

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function canFinanceExecute(object $actor, PosRefund $refund): bool
    {
        if ((string) $refund->module_type !== 'repair') {
            return false;
        }

        if ((int) ($actor->shop_owner_id ?? 0) !== (int) $refund->shop_owner_id) {
            return false;
        }

        return method_exists($actor, 'can')
            ? $actor->can('access-refund-approval')
            : true;
    }
}
