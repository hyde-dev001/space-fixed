<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Notifications\HR\PayslipGenerated;
use App\Services\NotificationService;
use App\Traits\HR\LogsHRActivity;
use Illuminate\Database\Eloquent\Builder;
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
 * Permission required: approve-payroll or access-payslip-approval
 *
 * Note: This controller intentionally reads HR models (Payroll,
 * PayrollComponent) — Finance is a consumer of HR data, not a generator.
 * Activity is logged to the HR audit trail since it affects payroll records.
 */
class PayslipApprovalController extends Controller
{
    use LogsHRActivity;

    public function __construct(private NotificationService $notificationService)
    {
    }

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function authorizeWorkflowViewer(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();

        return ($user && (
            $user->hasRole('Shop Owner')
            || $user->can('approve-payroll')
            || $user->can('access-payslip-approval')
            || $user->can('access-approval-workflow')
        )) ? $user : null;
    }

    private function authorizeChecker(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();

        return ($user && (
            $user->can('approve-payroll')
            || $user->can('access-payslip-approval')
        )) ? $user : null;
    }

    private function authorizeFinalApprover(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();

        return ($user && (
            $user->hasRole('Shop Owner')
            || $user->can('access-approval-workflow')
        )) ? $user : null;
    }

    // ============================================================
    // ENDPOINTS
    // ============================================================

    /**
     * List payroll records across the full maker-checker workflow.
     */
    public function getPayslipsForApproval(Request $request): JsonResponse
    {
        $user = $this->authorizeWorkflowViewer();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $baseQuery = Payroll::forShopOwner($user->shop_owner_id);

        $query = (clone $baseQuery)
            ->with([
                'employee:id,employee_id,first_name,last_name,department,position',
                'checker:id,name',
                'finalApprover:id,name',
                'disburser:id,name',
            ]);

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('payroll_period', 'like', "%{$term}%")
                  ->orWhere('employee_id', 'like', "%{$term}%")
                  ->orWhereHas('employee', fn ($e) =>
                      $e->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('employee_id', 'like', "%{$term}%")
                        ->orWhere('department', 'like', "%{$term}%")
                        ->orWhere('position', 'like', "%{$term}%")
                  );
            });
        }

        if ($request->filled('status')) {
            $query->where('approval_status', $request->status);
        }

        if ($request->filled('workflow_status')) {
            $this->applyWorkflowStatusFilter($query, (string) $request->workflow_status);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);
        $mapped = $paginator->getCollection()->map(fn (Payroll $payslip) => $this->transformPayslip($payslip));

        return response()->json([
            'data' => $mapped->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $this->buildSummary($baseQuery),
        ]);
    }

    /**
     * Show a single payroll item for review, final approval, or disbursement.
     */
    public function getPayslipForApproval(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeWorkflowViewer();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with([
                'employee:id,employee_id,first_name,last_name,department,position',
                'components',
                'checker:id,name',
                'finalApprover:id,name',
                'disburser:id,name',
            ])
            ->find($id);

        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        return response()->json($this->transformPayslip($payslip, true));
    }

    /**
     * Finance checker approval.
     */
    public function approvePayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeChecker();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with(['employee:id,employee_id,first_name,last_name,department,position'])
            ->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->approval_status === 'approved') {
            return response()->json(['error' => 'Payslip already checker-approved'], 400);
        }

        if ((int) ($payslip->generated_by ?? 0) === (int) $user->id) {
            return response()->json([
                'error' => 'Maker-checker violation. The payroll generator cannot act as the Finance checker.',
            ], 403);
        }

        try {
            $payslip->update([
                'status' => 'pending',
                'approval_status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $request->notes ?? '',
                'final_approved_by' => null,
                'final_approved_at' => null,
                'final_approval_notes' => null,
                'payout_reference' => null,
                'payout_proof_type' => null,
                'payout_proof_reference' => null,
                'payout_proof_notes' => null,
                'disbursed_by' => null,
                'disbursed_at' => null,
            ]);

            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_checker_approved',
                'Payslip Checker Approved',
                "Payslip #{$payslip->id} checker-approved by {$user->name} (Finance)",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip checker-approved successfully',
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,employee_id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to approve payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a payslip and send it back to HR for correction.
     */
    public function rejectPayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeChecker();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with(['employee:id,employee_id,first_name,last_name,department,position'])
            ->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->status === 'approved' || $payslip->status === 'paid') {
            return response()->json(['error' => 'Final-approved or paid payrolls cannot be rejected from the checker queue'], 400);
        }

        if ($payslip->approval_status === 'rejected') {
            return response()->json(['error' => 'Payslip already rejected'], 400);
        }

        try {
            $payslip->update([
                'status' => 'pending',
                'approval_status' => 'rejected',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $request->notes,
                'final_approved_by' => null,
                'final_approved_at' => null,
                'final_approval_notes' => null,
                'payout_reference' => null,
                'payout_proof_type' => null,
                'payout_proof_reference' => null,
                'payout_proof_notes' => null,
                'disbursed_by' => null,
                'disbursed_at' => null,
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
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,employee_id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reject payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Final approval before payroll can be disbursed.
     */
    public function finalApprovePayslip(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeFinalApprover();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with([
                'employee:id,employee_id,first_name,last_name,department,position',
                'checker:id,name',
                'finalApprover:id,name',
                'disburser:id,name',
            ])
            ->find($id);

        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->status === 'paid') {
            return response()->json(['error' => 'Paid payrolls cannot be final-approved again'], 400);
        }

        if ($payslip->approval_status !== 'approved' || empty($payslip->approved_by)) {
            return response()->json(['error' => 'Finance checker approval is required before final approval'], 422);
        }

        if (! empty($payslip->final_approved_by) || $payslip->status === 'approved') {
            return response()->json(['error' => 'Payroll already has final approval'], 400);
        }

        if ((int) ($payslip->generated_by ?? 0) === (int) $user->id) {
            return response()->json(['error' => 'Maker-checker violation. The payroll generator cannot be the final approver.'], 403);
        }

        if ((int) ($payslip->approved_by ?? 0) === (int) $user->id) {
            return response()->json(['error' => 'Maker-checker violation. The Finance checker cannot also be the final approver.'], 403);
        }

        try {
            $payslip->markAsFinalApproved((int) $user->id, $request->input('notes'));

            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_final_approved',
                'Payslip Final Approved',
                "Payslip #{$payslip->id} final-approved by {$user->name}",
                $payslip
            );

            try {
                if ($payslip->employee && $payslip->employee->user) {
                    if ($payslip->employee->user_id) {
                        $this->notificationService->notifyPayslipReady($payslip->employee->user_id, $user->shop_owner_id, [
                            'payroll_id' => $payslip->id,
                            'period' => $payslip->payroll_period,
                            'net_salary' => number_format((float) $payslip->net_salary, 2),
                        ]);
                    }

                    $payslip->employee->user->notify(new PayslipGenerated($payslip));
                }
            } catch (\Exception $notificationError) {
                \Log::error('Failed to send final approval payslip notification', [
                    'payroll_id' => $payslip->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payroll final-approved and ready for disbursement',
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,employee_id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to final-approve payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Preview summary of all pending payslips before batch-approving.
     */
    public function batchApprovalPreview(Request $request): JsonResponse
    {
        $user = $this->authorizeChecker();
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
        $user = $this->authorizeChecker();
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
                    'status' => 'pending',
                    'approval_status' => 'approved',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'approval_notes' => $request->notes ?? 'Batch approved by Finance',
                    'final_approved_by' => null,
                    'final_approved_at' => null,
                    'final_approval_notes' => null,
                    'payout_reference' => null,
                    'payout_proof_type' => null,
                    'payout_proof_reference' => null,
                    'payout_proof_notes' => null,
                    'disbursed_by' => null,
                    'disbursed_at' => null,
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

    private function applyWorkflowStatusFilter(Builder $query, string $workflowStatus): void
    {
        switch ($workflowStatus) {
            case 'awaiting_checker':
                $query->where('approval_status', 'pending');
                break;
            case 'awaiting_final_approval':
                $query->where('approval_status', 'approved')
                    ->where('status', 'pending');
                break;
            case 'ready_for_disbursement':
                $query->where('status', 'approved');
                break;
            case 'paid':
                $query->where('status', 'paid');
                break;
            case 'rejected':
                $query->where('approval_status', 'rejected');
                break;
        }
    }

    private function buildSummary(Builder $baseQuery): array
    {
        return [
            'total' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('approval_status', 'pending')->count(),
            'approved' => (clone $baseQuery)->where('approval_status', 'approved')->count(),
            'rejected' => (clone $baseQuery)->where('approval_status', 'rejected')->count(),
            'awaiting_finance' => (clone $baseQuery)->where('approval_status', 'pending')->count(),
            'awaiting_final_approval' => (clone $baseQuery)->where('approval_status', 'approved')->where('status', 'pending')->count(),
            'ready_for_disbursement' => (clone $baseQuery)->where('status', 'approved')->count(),
            'paid' => (clone $baseQuery)->where('status', 'paid')->count(),
        ];
    }

    private function transformPayslip(Payroll $payslip, bool $includeLineItems = false): array
    {
        $employee = $payslip->employee;
        $components = $includeLineItems ? $payslip->components : collect();

        return [
            'id' => $payslip->id,
            'employee_name' => $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : 'Unknown',
            'employee_id' => (string) ($employee->employee_id ?? $payslip->employee_id),
            'department' => $employee->department ?? 'N/A',
            'role' => $employee->position ?? 'N/A',
            'pay_period' => $payslip->payroll_period,
            'generated_date' => $payslip->created_at?->format('Y-m-d') ?? '',
            'generated_by' => 'HR Payroll',
            'gross_pay' => (float) ($payslip->gross_salary ?? 0),
            'deductions' => (float) ($payslip->total_deductions ?? $payslip->deductions ?? 0),
            'net_pay' => (float) ($payslip->net_salary ?? 0),
            'tax_amount' => (float) ($payslip->tax_amount ?? $payslip->tax_deductions ?? 0),
            'status' => $payslip->approval_status ?? 'pending',
            'workflow_status' => $payslip->workflow_status,
            'final_approval_status' => $payslip->final_approved_by ? 'approved' : 'pending',
            'disbursement_status' => $payslip->disbursement_status,
            'notes' => $payslip->notes ?? '',
            'approval_notes' => $payslip->approval_notes ?? '',
            'final_approval_notes' => $payslip->final_approval_notes ?? '',
            'checker_name' => $payslip->checker?->name,
            'checker_approved_at' => $payslip->approved_at?->format('Y-m-d H:i:s'),
            'final_approver_name' => $payslip->finalApprover?->name,
            'final_approved_at' => $payslip->final_approved_at?->format('Y-m-d H:i:s'),
            'payment_method' => $payslip->payment_method,
            'payment_date' => $payslip->payment_date?->format('Y-m-d'),
            'payout_reference' => $payslip->payout_reference,
            'payout_proof_type' => $payslip->payout_proof_type,
            'payout_proof_reference' => $payslip->payout_proof_reference,
            'payout_proof_notes' => $payslip->payout_proof_notes ?? '',
            'disbursed_by_name' => $payslip->disburser?->name,
            'disbursed_at' => $payslip->disbursed_at?->format('Y-m-d H:i:s'),
            'line_items' => $components->map(fn ($component) => [
                'label' => $component->component_name,
                'amount' => (float) ($component->calculated_amount ?? $component->amount ?? 0),
                'type' => $component->component_type,
            ])->values()->toArray(),
        ];
    }
}
