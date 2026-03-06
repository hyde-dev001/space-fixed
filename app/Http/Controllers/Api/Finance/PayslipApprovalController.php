<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * PayslipApprovalController (Finance module)
 *
 * Handles the Finance-level approval workflow for HR-generated payslips.
 * HR generates payrolls; Finance (or a designated approver) reviews and
 * approves / rejects them before they are released to employees.
 *
 * Routes: /api/finance/payslip-approvals/...
 * Permission required: approve-payroll
 *
 * Note: This controller intentionally reads HR models (Payroll,
 * PayrollComponent) — Finance is a consumer of HR data, not a generator.
 * Activity is logged to the HR audit trail since it affects payroll records.
 */
class PayslipApprovalController extends Controller
{
    use LogsHRActivity;

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function authorizeApprover(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();
        return $user->can('approve-payroll') ? $user : null;
    }

    // ============================================================
    // ENDPOINTS
    // ============================================================

    /**
     * List payslips awaiting approval (pending status).
     */
    public function getPayslipsForApproval(Request $request): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Payroll::forShopOwner($user->shop_owner_id)
            ->where('status', 'pending')
            ->with('employee:id,first_name,last_name,department,designation');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('payroll_period', 'like', "%{$term}%")
                  ->orWhereHas('employee', fn ($e) =>
                      $e->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                  );
            });
        }

        if ($request->filled('status')) {
            $query->where('approval_status', $request->status);
        }

        $payslips = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);

        return response()->json($payslips);
    }

    /**
     * Show a single payslip for review, including all line-items.
     */
    public function getPayslipForApproval(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee:id,first_name,last_name,department,designation', 'components')
            ->find($id);

        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        $components = $payslip->components;

        return response()->json([
            'id'             => $payslip->id,
            'employee_name'  => $payslip->employee->first_name . ' ' . $payslip->employee->last_name,
            'employee_id'    => $payslip->employee_id,
            'department'     => $payslip->employee->department,
            'role'           => $payslip->employee->designation,
            'pay_period'     => $payslip->payroll_period,
            'generated_date' => $payslip->created_at->format('Y-m-d'),
            'generated_by'   => 'HR Payroll',
            'gross_pay'      => $payslip->gross_salary,
            'deductions'     => $payslip->total_deductions,
            'net_pay'        => $payslip->net_salary,
            'tax_amount'     => $payslip->tax_amount ?? 0,
            'status'         => $payslip->approval_status ?? 'pending',
            'notes'          => $payslip->notes ?? '',
            'line_items'     => $components->map(fn ($c) => [
                'label'  => $c->component_name,
                'amount' => $c->calculated_amount,
                'type'   => $c->component_type,
            ])->values()->toArray(),
        ]);
    }

    /**
     * Approve a single payslip.
     */
    public function approvePayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->approval_status === 'approved') {
            return response()->json(['error' => 'Payslip already approved'], 400);
        }

        try {
            $payslip->update([
                'approval_status' => 'approved',
                'approved_by'     => $user->id,
                'approved_at'     => now(),
                'approval_notes'  => $request->notes ?? '',
            ]);

            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_approved',
                'Payslip Approved',
                "Payslip #{$payslip->id} approved by {$user->name} (Finance)",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip approved successfully',
                'payslip' => $payslip,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to approve payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a payslip and send it back to HR for correction.
     * A reason is required.
     */
    public function rejectPayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->approval_status === 'rejected') {
            return response()->json(['error' => 'Payslip already rejected'], 400);
        }

        try {
            $payslip->update([
                'approval_status' => 'rejected',
                'approved_by'     => $user->id,
                'approved_at'     => now(),
                'approval_notes'  => $request->notes,
            ]);

            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_rejected',
                'Payslip Rejected',
                "Payslip #{$payslip->id} rejected by {$user->name} (Finance): {$request->notes}",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip rejected and sent back to HR for correction',
                'payslip' => $payslip,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reject payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Preview summary of all pending payslips before batch-approving.
     */
    public function batchApprovalPreview(Request $request): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $payslips = Payroll::forShopOwner($user->shop_owner_id)
                ->where('approval_status', 'pending')
                ->with(['employee'])
                ->get();

            $previews = $payslips->map(fn ($p) => [
                'id'            => $p->id,
                'employee_id'   => $p->employee_id,
                'employee_name' => $p->employee->first_name . ' ' . $p->employee->last_name,
                'department'    => $p->employee->department,
                'pay_period'    => $p->payroll_period,
                'gross_pay'     => $p->gross_salary,
                'net_pay'       => $p->net_salary,
                'status'        => $p->approval_status,
            ]);

            return response()->json([
                'previews' => $previews,
                'summary'  => [
                    'count'       => $payslips->count(),
                    'total_gross' => $payslips->sum('gross_salary'),
                    'total_net'   => $payslips->sum('net_salary'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load preview: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Approve multiple payslips in one request.
     */
    public function batchApprove(Request $request): JsonResponse
    {
        $user = $this->authorizeApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payslip_ids'   => 'required|array',
            'payslip_ids.*' => 'required|integer|exists:payrolls,id',
            'notes'         => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $approvedCount = 0;
        $failedCount   = 0;
        $errors        = [];

        foreach ($request->payslip_ids as $payslipId) {
            try {
                $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($payslipId);

                if (! $payslip) {
                    $errors[] = "Payslip #{$payslipId} not found";
                    $failedCount++;
                    continue;
                }

                if ($payslip->approval_status !== 'pending') {
                    $errors[] = "Payslip #{$payslipId} is not pending";
                    $failedCount++;
                    continue;
                }

                $payslip->update([
                    'approval_status' => 'approved',
                    'approved_by'     => $user->id,
                    'approved_at'     => now(),
                    'approval_notes'  => $request->notes ?? 'Batch approved by Finance',
                ]);

                $this->logHRActivity(
                    $user->shop_owner_id,
                    'payslip_approved',
                    'Payslip Batch Approved',
                    "Payslip #{$payslip->id} approved by {$user->name} (Finance, batch)",
                    $payslip
                );

                $approvedCount++;
            } catch (\Exception $e) {
                $errors[]    = "Failed to approve payslip #{$payslipId}: " . $e->getMessage();
                $failedCount++;
            }
        }

        return response()->json([
            'message'  => "Approved {$approvedCount} payslip(s) successfully",
            'approved' => $approvedCount,
            'failed'   => $failedCount,
            'errors'   => $errors,
        ]);
    }
}
