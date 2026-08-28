<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Notifications\HR\PayslipGenerated;
use App\Services\NotificationService;
use App\Services\PayslipApprovalService;
use App\Traits\HR\LogsHRActivity;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

    public function __construct(
        private NotificationService $notificationService,
        private PayslipApprovalService $payslipApprovalService
    )
    {
    }

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function resolveShopOwnerActorUserId(int $shopOwnerId): ?int
    {
        $shopOwner = ShopOwner::query()->select('id', 'email')->find($shopOwnerId);

        $mappedByPermissionRole = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['Shop Owner', 'SHOP_OWNER', 'shop_owner', 'shop-owner']))
            ->orderByDesc('id')
            ->value('id');

        if ($mappedByPermissionRole) {
            return (int) $mappedByPermissionRole;
        }

        if ($shopOwner && ! empty($shopOwner->email)) {
            $mappedByEmail = User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $shopOwner->email)])
                ->orderByDesc('id')
                ->value('id');

            if ($mappedByEmail) {
                return (int) $mappedByEmail;
            }
        }

        return null;
    }

    private function ensureShopOwnerActorUserId($shopOwner): ?int
    {
        if (! isset($shopOwner->id)) {
            return null;
        }

        $resolvedId = $this->resolveShopOwnerActorUserId((int) $shopOwner->id);
        if ($resolvedId) {
            return $resolvedId;
        }

        $primaryEmail = strtolower(trim((string) ($shopOwner->email ?? '')));
        $fallbackEmail = 'shopowner+' . $shopOwner->id . '@solespace.local';
        $candidateEmails = array_values(array_unique(array_filter([$primaryEmail, $fallbackEmail])));

        foreach ($candidateEmails as $candidateEmail) {
            $existingUser = User::query()->whereRaw('LOWER(email) = ?', [strtolower($candidateEmail)])->first();
            if (! $existingUser) {
                continue;
            }

            if (! empty($existingUser->shop_owner_id) && (int) $existingUser->shop_owner_id !== (int) $shopOwner->id) {
                continue;
            }

            $existingUser->shop_owner_id = (int) $shopOwner->id;
            if (empty($existingUser->name)) {
                $existingUser->name = trim((string) ($shopOwner->first_name ?? '') . ' ' . (string) ($shopOwner->last_name ?? ''));
            }
            if (empty($existingUser->email_verified_at)) {
                $existingUser->email_verified_at = now();
            }
            $existingUser->save();

            try {
                if (! $existingUser->hasRole('Shop Owner')) {
                    $existingUser->assignRole('Shop Owner');
                }
            } catch (\Throwable $e) {
            }

            return (int) $existingUser->id;
        }

        $firstName = (string) ($shopOwner->first_name ?? 'Shop');
        $lastName = (string) ($shopOwner->last_name ?? 'Owner');
        $name = trim($firstName . ' ' . $lastName);

        try {
            $newUser = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'name' => $name !== '' ? $name : ('Shop Owner #' . $shopOwner->id),
                'email' => $fallbackEmail,
                'password' => Hash::make(Str::random(40)),
                'shop_owner_id' => (int) $shopOwner->id,
                'email_verified_at' => now(),
            ]);

            try {
                $newUser->assignRole('Shop Owner');
            } catch (\Throwable $e) {
            }

            return (int) $newUser->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function authorizeWorkflowViewer(): ?array
    {
        $user = Auth::guard('user')->user();

        if ($user && (
            $user->hasRole('Shop Owner')
            || $user->can('approve-payroll')
            || $user->can('access-payslip-approval')
            || $user->can('access-approval-workflow')
        )) {
            return [
                'shop_owner_id' => (int) $user->shop_owner_id,
                'actor_user_id' => (int) $user->id,
                'name' => (string) ($user->name ?? 'User'),
                'guard' => 'user',
            ];
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            $actorUserId = $this->ensureShopOwnerActorUserId($shopOwner);
            return [
                'shop_owner_id' => (int) $shopOwner->id,
                'actor_user_id' => $actorUserId,
                'name' => trim((string) ($shopOwner->full_name ?? $shopOwner->business_name ?? 'Shop Owner')),
                'guard' => 'shop_owner',
            ];
        }

        return null;
    }

    private function authorizeChecker(): ?array
    {
        $user = Auth::guard('user')->user();

        return ($user && (
            $user->can('approve-payroll')
            || $user->can('access-payslip-approval')
        )) ? [
            'shop_owner_id' => (int) $user->shop_owner_id,
            'actor_user_id' => (int) $user->id,
            'name' => (string) ($user->name ?? 'User'),
            'guard' => 'user',
        ] : null;
    }

    private function authorizeFinalApprover(): ?array
    {
        $user = Auth::guard('user')->user();

        $userRole = (string) ($user->role ?? '');

        if ($user && (
            $user->hasRole('Shop Owner')
            || in_array($userRole, ['Shop Owner', 'SHOP_OWNER', 'shop_owner'], true)
        )) {
            return [
                'shop_owner_id' => (int) $user->shop_owner_id,
                'actor_user_id' => (int) $user->id,
                'name' => (string) ($user->name ?? 'User'),
                'guard' => 'user',
            ];
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if ($shopOwner) {
            $actorUserId = $this->ensureShopOwnerActorUserId($shopOwner);
            return [
                'shop_owner_id' => (int) $shopOwner->id,
                'actor_user_id' => $actorUserId,
                'name' => trim((string) ($shopOwner->full_name ?? $shopOwner->business_name ?? 'Shop Owner')),
                'guard' => 'shop_owner',
            ];
        }

        return null;
    }

    // ============================================================
    // ENDPOINTS
    // ============================================================

    /**
     * List payroll records across the full maker-checker workflow.
     */
    public function getPayslipsForApproval(Request $request): JsonResponse
    {
        $actor = $this->authorizeWorkflowViewer();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $baseQuery = Payroll::forShopOwner($actor['shop_owner_id']);

        $query = (clone $baseQuery)
            ->with([
                'employee:id,first_name,last_name,department,position',
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
        $actor = $this->authorizeWorkflowViewer();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($actor['shop_owner_id'])
            ->with([
                'employee:id,first_name,last_name,department,position',
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
        $actor = $this->authorizeChecker();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($actor['shop_owner_id'])
            ->with(['employee:id,first_name,last_name,department,position'])
            ->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        // If payslip has new 4-step approval workflow, use it
        if ($payslip->approval_id && $payslip->approval_workflow_version === 'v4_multi_level') {
            $result = $this->payslipApprovalService->approvePayslip(
                $payslip,
                User::find($actor['actor_user_id']),
                $request->input('notes')
            );

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['message'],
                    'details' => $result
                ], 422);
            }

            $payslip->refresh();

            $this->logHRActivity(
                $actor['shop_owner_id'],
                'payslip_approved_level_' . $payslip->current_approval_level,
                'Payslip Approved - Level ' . $payslip->current_approval_level,
                "Payslip #{$payslip->id} approved at level {$payslip->current_approval_level} by {$actor['name']}",
                $payslip
            );

            return response()->json([
                'message' => $result['message'],
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
                'is_final' => $result['is_final'] ?? false,
                'approval_level' => $payslip->current_approval_level,
            ]);
        }

        // Fallback to legacy 2-step approval for existing payslips
        if ($payslip->approval_status === 'approved') {
            return response()->json(['error' => 'Payslip already checker-approved'], 400);
        }

        try {
            $payslip->update([
                'status' => 'pending',
                'approval_status' => 'approved',
                'approved_by' => $actor['actor_user_id'],
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
                $actor['shop_owner_id'],
                'payslip_checker_approved',
                'Payslip Checker Approved',
                "Payslip #{$payslip->id} checker-approved by {$actor['name']} (Finance)",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip checker-approved successfully',
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'payslip.approve', 500, ['record_id' => $id, 'shop_id' => $actor['shop_owner_id']]);
        }
    }

    /**
     * Reject a payslip and send it back to HR for correction.
     */
    public function rejectPayslip(Request $request, $id): JsonResponse
    {
        $actor = $this->authorizeChecker();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $notes = trim((string) $request->input('notes', ''));

        $validator = Validator::make([
            'notes' => $notes,
        ], [
            'notes' => 'required|string|min:3|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($actor['shop_owner_id'])
            ->with(['employee:id,first_name,last_name,department,position'])
            ->find($id);
        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        // If payslip has new 4-step approval workflow, use it
        if ($payslip->approval_id && $payslip->approval_workflow_version === 'v4_multi_level') {
            $result = $this->payslipApprovalService->rejectPayslip(
                $payslip,
                User::find($actor['actor_user_id']),
                $notes
            );

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['message'],
                    'details' => $result
                ], 422);
            }

            $payslip->refresh();

            $this->logHRActivity(
                $actor['shop_owner_id'],
                'payslip_rejected_level_' . $payslip->current_approval_level,
                'Payslip Rejected - Level ' . $payslip->current_approval_level,
                "Payslip #{$payslip->id} rejected at level {$payslip->current_approval_level}: {$notes}",
                $payslip
            );

            return response()->json([
                'message' => $result['message'],
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
                'rejection_level' => $payslip->current_approval_level,
            ]);
        }

        // Fallback to legacy 2-step rejection for existing payslips
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
                'approved_by' => $actor['actor_user_id'],
                'approved_at' => now(),
                'approval_notes' => $notes,
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
                $actor['shop_owner_id'],
                'payslip_rejected',
                'Payslip Rejected',
                "Payslip #{$payslip->id} rejected by {$actor['name']} (Finance): {$notes}",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip rejected and sent back to HR for correction',
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'payslip.reject', 500, ['record_id' => $id, 'shop_id' => $actor['shop_owner_id']]);
        }
    }

    /**
     * Final approval before payroll can be disbursed.
     * For 4-step workflows: can be called by Shop Owner (level 2) or Finance Manager (level 4)
     * For legacy workflows: called by Shop Owner only
     */
    public function finalApprovePayslip(Request $request, $id): JsonResponse
    {
        $actor = $this->authorizeFinalApprover();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (empty($actor['actor_user_id'])) {
            return response()->json([
                'error' => 'No linked ERP user was found for this shop owner. Please contact support to map the account before final approval.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($actor['shop_owner_id'])
            ->with([
                'employee:id,first_name,last_name,department,position,email',
                'checker:id,name',
                'finalApprover:id,name',
                'disburser:id,name',
            ])
            ->find($id);

        if (! $payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        // If payslip has new 4-step approval workflow, use it
        if ($payslip->approval_id && $payslip->approval_workflow_version === 'v4_multi_level') {
            $result = $this->payslipApprovalService->approvePayslip(
                $payslip,
                User::find($actor['actor_user_id']),
                $request->input('notes')
            );

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['message'],
                    'details' => $result
                ], 422);
            }

            $payslip->refresh();

            $this->logHRActivity(
                $actor['shop_owner_id'],
                'payslip_approved_level_' . $payslip->current_approval_level,
                'Payslip Approved - Level ' . $payslip->current_approval_level,
                "Payslip #{$payslip->id} approved at level {$payslip->current_approval_level} by {$actor['name']}",
                $payslip
            );

            return response()->json([
                'message' => $result['message'],
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
                'is_final' => $result['is_final'] ?? false,
                'approval_level' => $payslip->current_approval_level,
            ]);
        }

        // Fallback to legacy 2-step for existing payslips
        if ($payslip->status === 'paid') {
            return response()->json(['error' => 'Paid payrolls cannot be final-approved again'], 400);
        }

        if ($payslip->approval_status !== 'approved' || empty($payslip->approved_by)) {
            return response()->json(['error' => 'Finance checker approval is required before final approval'], 422);
        }

        if (! empty($payslip->final_approved_by) || $payslip->status === 'approved') {
            return response()->json(['error' => 'Payroll already has final approval'], 400);
        }

        try {
            $payslip->markAsFinalApproved((int) $actor['actor_user_id'], $request->input('notes'));

            $this->logHRActivity(
                $actor['shop_owner_id'],
                'payslip_final_approved',
                'Payslip Final Approved',
                "Payslip #{$payslip->id} final-approved by {$actor['name']}",
                $payslip
            );

            try {
                if ($payslip->employee && $payslip->employee->user) {
                    $employeeUserId = (int) ($payslip->employee->user?->id ?? 0);
                    if ($employeeUserId > 0) {
                        $this->notificationService->notifyPayslipReady($employeeUserId, $actor['shop_owner_id'], [
                            'payroll_id' => $payslip->id,
                            'period' => $payslip->payroll_period,
                            'net_salary' => number_format((float) $payslip->net_salary, 2),
                        ]);
                    }

                    $payslip->employee->user->notify(new PayslipGenerated($payslip));
                }
            } catch (\Exception $notificationError) {
                Log::error('Failed to send final approval payslip notification', [
                    'payroll_id' => $payslip->id,
                    'error' => $notificationError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payroll final-approved and ready for disbursement',
                'payslip' => $this->transformPayslip($payslip->fresh([
                    'employee:id,first_name,last_name,department,position',
                    'checker:id,name',
                    'finalApprover:id,name',
                    'disburser:id,name',
                ])),
            ]);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'payslip.final_approve', 500, ['record_id' => $id, 'shop_id' => $actor['shop_owner_id']]);
        }
    }

    /**
     * Preview summary of all pending payslips before batch-approving.
     */
    public function batchApprovalPreview(Request $request): JsonResponse
    {
        $actor = $this->authorizeChecker();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $payslips = Payroll::forShopOwner($actor['shop_owner_id'])
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
            return FinanceErrorResponse::json($e, 'payslip.batch_preview', 500, ['shop_id' => $actor['shop_owner_id']]);
        }
    }

    /**
     * Approve multiple payslips in one request.
     */
    public function batchApprove(Request $request): JsonResponse
    {
        $actor = $this->authorizeChecker();
        if (! $actor) {
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
                $payslip = Payroll::forShopOwner($actor['shop_owner_id'])->find($payslipId);

                if (! $payslip) {
                    $errors[] = "Payslip #{$payslipId} not found";
                    $failedCount++;
                    continue;
                }

                if ($payslip->approval_id && $payslip->approval_workflow_version === 'v4_multi_level') {
                    $result = $this->payslipApprovalService->approvePayslip(
                        $payslip,
                        User::find($actor['actor_user_id']),
                        $request->input('notes')
                    );

                    if (! ($result['success'] ?? false)) {
                        $errors[] = "Payslip #{$payslipId}: " . ($result['message'] ?? 'Approval failed');
                        $failedCount++;
                        continue;
                    }

                    $payslip->refresh();

                    $this->logHRActivity(
                        $actor['shop_owner_id'],
                        'payslip_approved_level_' . $payslip->current_approval_level,
                        'Payslip Batch Approved',
                        "Payslip #{$payslip->id} approved at level {$payslip->current_approval_level} by {$actor['name']} (Finance, batch)",
                        $payslip
                    );

                    $approvedCount++;
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
                    'approved_by' => $actor['actor_user_id'],
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
                    $actor['shop_owner_id'],
                    'payslip_approved',
                    'Payslip Batch Approved',
                    "Payslip #{$payslip->id} approved by {$actor['name']} (Finance, batch)",
                    $payslip
                );

                $approvedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to approve payslip in batch', [
                    'payslip_id' => $payslipId,
                    'shop_id' => $actor['shop_owner_id'],
                    'exception' => $e,
                ]);
                $errors[]    = "Failed to approve payslip #{$payslipId}.";
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

    /**
     * Final-approve multiple checker-approved payslips (Shop Owner bulk action).
     */
    public function batchFinalApprove(Request $request): JsonResponse
    {
        $actor = $this->authorizeFinalApprover();
        if (! $actor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (empty($actor['actor_user_id'])) {
            return response()->json([
                'error' => 'No linked ERP user was found for this shop owner. Please contact support to map the account before final approval.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'payslip_ids' => 'required|array',
            'payslip_ids.*' => 'required|integer|exists:payrolls,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $approvedCount = 0;
        $failedCount = 0;
        $errors = [];
        $notes = $request->input('notes');

        foreach ((array) $request->input('payslip_ids', []) as $payslipId) {
            try {
                $payslip = Payroll::forShopOwner($actor['shop_owner_id'])
                    ->with([
                        'employee:id,first_name,last_name,department,position,email',
                        'employee.user:id,name,email',
                    ])
                    ->find($payslipId);

                if (! $payslip) {
                    $errors[] = "Payslip #{$payslipId} not found";
                    $failedCount++;
                    continue;
                }

                if ($payslip->approval_id && $payslip->approval_workflow_version === 'v4_multi_level') {
                    $result = $this->payslipApprovalService->approvePayslip(
                        $payslip,
                        User::find($actor['actor_user_id']),
                        $notes
                    );

                    if (! ($result['success'] ?? false)) {
                        $errors[] = "Payslip #{$payslipId}: " . ($result['message'] ?? 'Final approval failed');
                        $failedCount++;
                        continue;
                    }

                    $payslip->refresh();

                    $this->logHRActivity(
                        $actor['shop_owner_id'],
                        'payslip_approved_level_' . $payslip->current_approval_level,
                        'Payslip Approved - Level ' . $payslip->current_approval_level,
                        "Payslip #{$payslip->id} approved at level {$payslip->current_approval_level} by {$actor['name']} (batch)",
                        $payslip
                    );

                    $approvedCount++;
                    continue;
                }

                if ($payslip->status === 'paid') {
                    $errors[] = "Payslip #{$payslipId}: Paid payrolls cannot be final-approved again";
                    $failedCount++;
                    continue;
                }

                if ($payslip->approval_status !== 'approved' || empty($payslip->approved_by)) {
                    $errors[] = "Payslip #{$payslipId}: Finance checker approval is required before final approval";
                    $failedCount++;
                    continue;
                }

                if (! empty($payslip->final_approved_by) || $payslip->status === 'approved') {
                    $errors[] = "Payslip #{$payslipId}: Payroll already has final approval";
                    $failedCount++;
                    continue;
                }

                $payslip->markAsFinalApproved((int) $actor['actor_user_id'], $notes);

                $this->logHRActivity(
                    $actor['shop_owner_id'],
                    'payslip_final_approved',
                    'Payslip Final Approved',
                    "Payslip #{$payslip->id} final-approved by {$actor['name']} (batch)",
                    $payslip
                );

                try {
                    if ($payslip->employee && $payslip->employee->user) {
                        $employeeUserId = (int) ($payslip->employee->user?->id ?? 0);
                        if ($employeeUserId > 0) {
                            $this->notificationService->notifyPayslipReady($employeeUserId, $actor['shop_owner_id'], [
                                'payroll_id' => $payslip->id,
                                'period' => $payslip->payroll_period,
                                'net_salary' => number_format((float) $payslip->net_salary, 2),
                            ]);
                        }

                        $payslip->employee->user->notify(new PayslipGenerated($payslip));
                    }
                } catch (\Exception $notificationError) {
                    Log::error('Failed to send batch final approval payslip notification', [
                        'payroll_id' => $payslip->id,
                        'error' => $notificationError->getMessage(),
                    ]);
                }

                $approvedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to final-approve payslip in batch', [
                    'payslip_id' => $payslipId,
                    'shop_id' => $actor['shop_owner_id'],
                    'exception' => $e,
                ]);
                $errors[] = "Failed to final-approve payslip #{$payslipId}.";
                $failedCount++;
            }
        }

        return response()->json([
            'message' => "Final-approved {$approvedCount} payslip(s) successfully",
            'approved' => $approvedCount,
            'failed' => $failedCount,
            'errors' => $errors,
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
        $grossPay = (float) ($payslip->gross_salary ?? 0);
        $netPay = (float) ($payslip->net_salary ?? 0);
        $taxAmount = (float) ($payslip->tax_amount ?? $payslip->tax_deductions ?? 0);
        $sssContribution = (float) ($payslip->sss_contributions ?? 0);
        $philhealthContribution = (float) ($payslip->philhealth ?? 0);
        $pagibigContribution = (float) ($payslip->pag_ibig ?? 0);
        $componentOrStoredDeductions = (float) ($payslip->total_deductions ?? 0);
        $legacyDeductions = (float) ($payslip->deductions ?? 0);
        $computedFromParts = $componentOrStoredDeductions + $taxAmount + $sssContribution + $philhealthContribution + $pagibigContribution;
        $derivedFromGrossNet = round(max(0, $grossPay - $netPay), 2);

        $effectiveDeductions = $legacyDeductions > 0
            ? $legacyDeductions
            : ($derivedFromGrossNet > 0
                ? $derivedFromGrossNet
                : $computedFromParts);

        $lineItems = $components->map(fn ($component) => [
            'label' => $component->component_name,
            'amount' => (float) ($component->calculated_amount ?? $component->amount ?? 0),
            'type' => $component->component_type,
        ]);

        if ($includeLineItems) {
            $existingLabels = $lineItems
                ->pluck('label')
                ->map(fn ($label) => strtolower(trim((string) $label)))
                ->values();

            $hasLabel = fn (array $labels): bool => collect($labels)
                ->map(fn ($label) => strtolower(trim($label)))
                ->contains(fn ($needle) => $existingLabels->contains($needle));

            if ($taxAmount > 0 && ! $hasLabel(['Income Tax', 'Withholding Tax'])) {
                $lineItems->push([
                    'label' => 'Withholding Tax',
                    'amount' => $taxAmount,
                    'type' => PayrollComponent::TYPE_DEDUCTION,
                ]);
            }

            if ($sssContribution > 0 && ! $hasLabel(['SSS Contribution'])) {
                $lineItems->push([
                    'label' => 'SSS Contribution',
                    'amount' => $sssContribution,
                    'type' => PayrollComponent::TYPE_DEDUCTION,
                ]);
            }

            if ($philhealthContribution > 0 && ! $hasLabel(['PhilHealth Contribution'])) {
                $lineItems->push([
                    'label' => 'PhilHealth Contribution',
                    'amount' => $philhealthContribution,
                    'type' => PayrollComponent::TYPE_DEDUCTION,
                ]);
            }

            if ($pagibigContribution > 0 && ! $hasLabel(['Pag-IBIG Contribution', 'Pagibig Contribution'])) {
                $lineItems->push([
                    'label' => 'Pag-IBIG Contribution',
                    'amount' => $pagibigContribution,
                    'type' => PayrollComponent::TYPE_DEDUCTION,
                ]);
            }
        }

        return [
            'id' => $payslip->id,
            'employee_name' => $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : 'Unknown',
            'employee_id' => (string) ($employee->employee_id ?? $payslip->employee_id),
            'department' => $employee->department ?? 'N/A',
            'role' => $employee->position ?? 'N/A',
            'pay_period' => $payslip->payroll_period,
            'generated_date' => $payslip->created_at?->format('Y-m-d') ?? '',
            'generated_by' => 'HR Payroll',
            'gross_pay' => $grossPay,
            'deductions' => round($effectiveDeductions, 2),
            'net_pay' => $netPay,
            'tax_amount' => $taxAmount,
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
            'line_items' => $lineItems->values()->toArray(),
        ];
    }
}
