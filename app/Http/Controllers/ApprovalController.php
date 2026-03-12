<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Approval;
use App\Models\ApprovalHistory;
use App\Services\NotificationService;

class ApprovalController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}
    
    /**
     * Get pending approval requests for the current user
     */
    public function getPending(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $user->shop_owner_id ?? $user->id;
            $userApprovalLimit = $user->approval_limit;

            // Check for active delegations
            $delegatedFrom = $this->getActiveDelegations($user->id);

            // Collect all pending approvals from different sources
            $approvals = collect();

            // 1. Get expenses with 'submitted' status that need approval - WITH STAFF INFO AND JOB CONTEXT
            $expenses = DB::table('finance_expenses as e')
                ->leftJoin('users as u', 'e.created_by', '=', 'u.id')
                ->leftJoin('employees as emp', 'u.id', '=', 'emp.user_id')
                ->leftJoin('orders as o', 'e.job_order_id', '=', 'o.id')
                ->where('e.shop_id', $shopOwnerId)
                ->where('e.status', 'submitted')
                ->select(
                    'e.*',
                    'u.name as created_by_name',
                    'emp.position as created_by_position',
                    'emp.employee_id as created_by_employee_id',
                    'o.id as job_order_id',
                    'o.customer as job_customer',
                    'o.product as job_product',
                    'o.status as job_status'
                )
                ->orderBy('e.created_at', 'desc')
                ->get()
                ->map(function ($expense) use ($userApprovalLimit) {
                    // Check if user has sufficient approval limit
                    $requiresHigherAuthority = false;
                    $approvalLimitWarning = null;
                    
                    if ($userApprovalLimit !== null && $expense->amount > $userApprovalLimit) {
                        $requiresHigherAuthority = true;
                        $approvalLimitWarning = [
                            'type' => 'insufficient_limit',
                            'message' => 'This transaction exceeds your approval limit',
                            'transaction_amount' => (float) $expense->amount,
                            'your_limit' => (float) $userApprovalLimit,
                            'difference' => (float) ($expense->amount - $userApprovalLimit)
                        ];
                    }

                    // Check if amount is approaching limit (within 80%)
                    if ($userApprovalLimit !== null && $expense->amount > ($userApprovalLimit * 0.8) && $expense->amount <= $userApprovalLimit) {
                        $approvalLimitWarning = [
                            'type' => 'approaching_limit',
                            'message' => 'This transaction is close to your approval limit',
                            'transaction_amount' => (float) $expense->amount,
                            'your_limit' => (float) $userApprovalLimit,
                            'percentage' => round(($expense->amount / $userApprovalLimit) * 100, 1)
                        ];
                    }

                    return [
                        'id' => $expense->id,
                        'type' => 'expense',
                        'reference' => $expense->reference,
                        'description' => $expense->description ?? $expense->category,
                        'amount' => (float) $expense->amount,
                        'requested_by' => $expense->created_by_name ?? 'Unknown',
                        'requested_at' => $expense->created_at,
                        'status' => 'pending',
                        'current_level' => 1,
                        'total_levels' => 1,
                        'requires_higher_authority' => $requiresHigherAuthority,
                        'approval_limit_warning' => $approvalLimitWarning,
                        'staff_info' => [
                            'name' => $expense->created_by_name,
                            'position' => $expense->created_by_position,
                            'employee_id' => $expense->created_by_employee_id
                        ],
                        'job_order' => $expense->job_order_id ? [
                            'id' => $expense->job_order_id,
                            'customer' => $expense->job_customer,
                            'product' => $expense->job_product,
                            'status' => $expense->job_status
                        ] : null,
                        'metadata' => [
                            'category' => $expense->category,
                            'vendor' => $expense->vendor,
                            'date' => $expense->date,
                        ]
                    ];
                });
            
            $approvals = $approvals->merge($expenses);

            // 2. Get approvals from the approvals table (for other types)
            $approvalRecords = Approval::where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->with(['requestedBy', 'approvableType'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($approval) {
                    return [
                        'id' => $approval->id,
                        'type' => $approval->approvable_type ? class_basename($approval->approvable_type) : 'unknown',
                        'reference' => $approval->reference,
                        'description' => $approval->description,
                        'amount' => (float) $approval->amount,
                        'requested_by' => $approval->requestedBy ? $approval->requestedBy->name : 'Unknown',
                        'requested_at' => $approval->created_at->toIso8601String(),
                        'status' => $approval->status,
                        'current_level' => $approval->current_level,
                        'total_levels' => $approval->total_levels,
                        'metadata' => $approval->metadata ? json_decode($approval->metadata, true) : null
                    ];
                });
            
            $approvals = $approvals->merge($approvalRecords);

            return response()->json(['approvals' => $approvals->values()->all()]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch pending approvals', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get approval history (approved/rejected requests)
     */
    public function getHistory(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $user->shop_owner_id ?? $user->id;

            // Collect all approval history from different sources
            $approvals = collect();

            // 1. Get expenses with 'approved', 'rejected', or 'posted' status
            $expenses = DB::table('finance_expenses')
                ->where('shop_id', $shopOwnerId)
                ->whereIn('status', ['approved', 'rejected', 'posted'])
                ->orderBy('updated_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($expense) {
                    return [
                        'id' => $expense->id,
                        'type' => 'expense',
                        'reference' => $expense->reference,
                        'description' => $expense->description ?? $expense->category,
                        'amount' => (float) $expense->amount,
                        'requested_by' => 'Staff',
                        'requested_at' => $expense->created_at,
                        'status' => $expense->status === 'posted' ? 'approved' : $expense->status,
                        'current_level' => 1,
                        'total_levels' => 1,
                        'reviewed_by' => $expense->approved_by ? 'Manager' : null,
                        'reviewed_at' => $expense->approved_at,
                        'comments' => $expense->approval_notes
                    ];
                });
            
            $approvals = $approvals->merge($expenses);

            // 2. Get completed approvals from the approvals table (for other types)
            $approvalRecords = Approval::where('shop_owner_id', $shopOwnerId)
                ->whereIn('status', ['approved', 'rejected', 'cancelled'])
                ->with(['requestedBy', 'reviewedBy'])
                ->orderBy('updated_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($approval) {
                    return [
                        'id' => $approval->id,
                        'type' => $approval->approvable_type ? class_basename($approval->approvable_type) : 'unknown',
                        'reference' => $approval->reference,
                        'description' => $approval->description,
                        'amount' => (float) $approval->amount,
                        'requested_by' => $approval->requestedBy ? $approval->requestedBy->name : 'Unknown',
                        'requested_at' => $approval->created_at->toIso8601String(),
                        'status' => $approval->status,
                        'current_level' => $approval->current_level,
                        'total_levels' => $approval->total_levels,
                        'reviewed_by' => $approval->reviewedBy ? $approval->reviewedBy->name : null,
                        'reviewed_at' => $approval->reviewed_at ? $approval->reviewed_at->toIso8601String() : null,
                        'comments' => $approval->comments
                    ];
                });
            
            $approvals = $approvals->merge($approvalRecords);

            return response()->json(['approvals' => $approvals->sortByDesc('reviewed_at')->values()->all()]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch approval history', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get approval history for a specific approval request
     */
    public function getApprovalHistory(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $user->shop_owner_id ?? $user->id;

            // Verify the approval belongs to the user's shop
            $approval = Approval::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$approval) {
                return response()->json(['error' => 'Approval not found'], 404);
            }

            // Get history records
            $history = ApprovalHistory::where('approval_id', $id)
                ->with('reviewer')
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'approval_id' => $record->approval_id,
                        'level' => $record->level,
                        'reviewer' => $record->reviewer ? $record->reviewer->name : 'Unknown',
                        'action' => $record->action,
                        'comments' => $record->comments,
                        'reviewed_at' => $record->created_at->toIso8601String()
                    ];
                });

            return response()->json(['history' => $history]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch approval history', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Approve a request
     */
    public function approve(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $user->shop_owner_id ?? $user->id;

            $request->validate([
                'comments' => 'nullable|string|max:1000'
            ]);

            DB::beginTransaction();

            // First, try to find expense in finance_expenses table
            $expense = DB::table('finance_expenses')
                ->where('id', $id)
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'submitted')
                ->first();

            if ($expense) {
                // This is an expense approval
                // Update the expense status to approved
                DB::table('finance_expenses')
                    ->where('id', $id)
                    ->update([
                        'status' => 'approved',
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                        'approval_notes' => $request->comments,
                        'updated_at' => now()
                    ]);

                // Send notification to requester
                if ($expense->created_by) {
                    $this->notificationService->send(
                        userId: $expense->created_by,
                        type: 'expense_approval',
                        title: 'Expense Approved',
                        message: "Your expense request of ₱{$expense->amount} has been approved",
                        data: [
                            'expense_id' => $expense->id,
                            'reference' => $expense->reference,
                            'amount' => $expense->amount,
                        ],
                        actionUrl: '/erp/finance/expenses',
                        shopId: $shopOwnerId
                    );
                }

                DB::commit();

                return response()->json([
                    'message' => 'Expense approved successfully',
                    'data' => [
                        'id' => $expense->id,
                        'reference' => $expense->reference,
                        'status' => 'approved'
                    ]
                ]);
            }

            // If not an expense, try to find in approvals table
            $approval = Approval::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$approval) {
                DB::rollBack();
                return response()->json(['error' => 'Approval request not found or already processed'], 404);
            }

            // Check approval limit
            $approvalLimit = $user->approval_limit;
            
            // If approval_limit is null, user has unlimited approval authority (e.g., Finance Manager)
            // If approval_limit is set, check if it's sufficient
            if ($approvalLimit !== null && $approval->amount > $approvalLimit) {
                DB::rollBack();
                return response()->json([
                    'error' => 'Insufficient approval authority',
                    'details' => [
                        'transaction_amount' => (float) $approval->amount,
                        'your_approval_limit' => (float) $approvalLimit,
                        'required_approver' => 'This transaction requires approval from a user with higher authority'
                    ]
                ], 403);
            }

            // Record in history
            ApprovalHistory::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'reviewer_id' => $user->id,
                'action' => 'approved',
                'comments' => $request->comments
            ]);

            // Check if this is the final approval level
            if ($approval->current_level >= $approval->total_levels) {
                // Final approval - update status to approved
                $approval->update([
                    'status' => 'approved',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                    'comments' => $request->comments
                ]);

                // TODO: Trigger the actual approval action (e.g., post journal entry, approve expense)
                // This would depend on the approvable_type and approvable_id
                $this->executeApprovalAction($approval);
            } else {
                // Move to next approval level
                $approval->update([
                    'current_level' => $approval->current_level + 1,
                    'comments' => $request->comments
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Request approved successfully',
                'approval' => $approval
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to approve request', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Reject a request
     */
    public function reject(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $user->shop_owner_id ?? $user->id;

            $request->validate([
                'comments' => 'required|string|max:1000'
            ]);

            DB::beginTransaction();

            // First, try to find expense in finance_expenses table
            $expense = DB::table('finance_expenses')
                ->where('id', $id)
                ->where('shop_id', $shopOwnerId)
                ->where('status', 'submitted')
                ->first();

            if ($expense) {
                // This is an expense rejection
                // Update the expense status to rejected
                DB::table('finance_expenses')
                    ->where('id', $id)
                    ->update([
                        'status' => 'rejected',
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                        'approval_notes' => $request->comments,
                        'updated_at' => now()
                    ]);

                // Send notification to requester
                if ($expense->created_by) {
                    $this->notificationService->send(
                        userId: $expense->created_by,
                        type: 'expense_approval',
                        title: 'Expense Rejected',
                        message: "Your expense request of ₱{$expense->amount} has been rejected",
                        data: [
                            'expense_id' => $expense->id,
                            'reference' => $expense->reference,
                            'amount' => $expense->amount,
                            'rejection_reason' => $request->comments,
                        ],
                        actionUrl: '/erp/finance/expenses',
                        shopId: $shopOwnerId
                    );
                }

                DB::commit();

                return response()->json([
                    'message' => 'Expense rejected',
                    'data' => [
                        'id' => $expense->id,
                        'reference' => $expense->reference,
                        'status' => 'rejected'
                    ]
                ]);
            }

            // If not an expense, try to find in approvals table
            $approval = Approval::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$approval) {
                DB::rollBack();
                return response()->json(['error' => 'Approval request not found or already processed'], 404);
            }

            // Check approval limit (same authority required to reject as to approve)
            $approvalLimit = $user->approval_limit;
            
            // If approval_limit is null, user has unlimited approval authority (e.g., Finance Manager)
            // If approval_limit is set, check if it's sufficient
            if ($approvalLimit !== null && $approval->amount > $approvalLimit) {
                DB::rollBack();
                return response()->json([
                    'error' => 'Insufficient approval authority',
                    'details' => [
                        'transaction_amount' => (float) $approval->amount,
                        'your_approval_limit' => (float) $approvalLimit,
                        'required_approver' => 'This transaction requires approval/rejection from a user with higher authority'
                    ]
                ], 403);
            }

            // Record in history
            ApprovalHistory::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'reviewer_id' => $user->id,
                'action' => 'rejected',
                'comments' => $request->comments
            ]);

            // Update approval status
            $approval->update([
                'status' => 'rejected',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'comments' => $request->comments
            ]);

            // TODO: Trigger rejection action if needed (e.g., notify requester)

            DB::commit();

            return response()->json([
                'message' => 'Request rejected',
                'approval' => $approval
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to reject request', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Execute the actual approval action based on the approvable type
     */
    private function executeApprovalAction(Approval $approval)
    {
        // This method should handle the actual approval action
        // For example:
        // - If approvable_type is JournalEntry, post the entry
        // - If approvable_type is Expense, mark as approved and process
        // - If approvable_type is Invoice, finalize the invoice
        // - etc.

        // TODO: Implement based on your specific requirements
        // Example structure:
        /*
        switch ($approval->approvable_type) {
            case 'App\\Models\\JournalEntry':
                $journalEntry = JournalEntry::find($approval->approvable_id);
                if ($journalEntry) {
                    $journalEntry->update(['status' => 'posted']);
                }
                break;
            case 'App\\Models\\Expense':
                $expense = Expense::find($approval->approvable_id);
                if ($expense) {
                    $expense->update(['status' => 'approved']);
                }
                break;
            // Add more cases as needed
        }
        */
    }

    /**
     * Create a new approval request (called from other controllers)
     */
    public static function createApprovalRequest($data)
    {
        try {
            $approval = Approval::create([
                'shop_owner_id' => $data['shop_owner_id'],
                'approvable_type' => $data['approvable_type'],
                'approvable_id' => $data['approvable_id'],
                'reference' => $data['reference'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'requested_by' => $data['requested_by'],
                'current_level' => 1,
                'total_levels' => $data['total_levels'] ?? 1,
                'status' => 'pending',
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
            ]);

            return $approval;
        } catch (\Exception $e) {
            \Log::error('Failed to create approval request: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get active delegations for a user
     */
    private function getActiveDelegations($userId)
    {
        return DB::table('approval_delegations')
            ->where('delegate_to_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->get();
    }

    /**
     * Create a delegation
     */
    public function createDelegation(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'delegate_to_id' => 'required|exists:users,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'reason' => 'nullable|string|max:500'
            ]);

            // Check if delegate has sufficient privileges
            $delegate = \App\Models\User::find($validated['delegate_to_id']);
            if (!$delegate || !$delegate->hasAnyRole(['Manager', 'Finance Manager'])) {
                return response()->json(['error' => 'Delegate must have manager role'], 400);
            }

            $delegationId = DB::table('approval_delegations')->insertGetId([
                'delegated_by_id' => $user->id,
                'delegate_to_id' => $validated['delegate_to_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'reason' => $validated['reason'] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Send notification to delegate
            $this->notificationService->notifyDelegationAssigned(
                delegateId: $validated['delegate_to_id'],
                delegationData: [
                    'delegated_by' => $user->name,
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'reason' => $validated['reason'] ?? null,
                ],
                shopId: $user->shop_owner_id ?? $user->id
            );

            return response()->json([
                'message' => 'Delegation created successfully',
                'delegation_id' => $delegationId
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create delegation', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get user's delegations
     */
    public function getDelegations(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $delegations = DB::table('approval_delegations as d')
                ->leftJoin('users as delegated_by', 'd.delegated_by_id', '=', 'delegated_by.id')
                ->leftJoin('users as delegate_to', 'd.delegate_to_id', '=', 'delegate_to.id')
                ->where(function($query) use ($user) {
                    $query->where('d.delegated_by_id', $user->id)
                          ->orWhere('d.delegate_to_id', $user->id);
                })
                ->select(
                    'd.*',
                    'delegated_by.name as delegated_by_name',
                    'delegate_to.name as delegate_to_name'
                )
                ->orderBy('d.created_at', 'desc')
                ->get();

            return response()->json(['delegations' => $delegations]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch delegations', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Deactivate a delegation
     */
    public function deactivateDelegation(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $delegation = DB::table('approval_delegations')
                ->where('id', $id)
                ->where('delegated_by_id', $user->id)
                ->first();

            if (!$delegation) {
                return response()->json(['error' => 'Delegation not found'], 404);
            }

            DB::table('approval_delegations')
                ->where('id', $id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now()
                ]);

            return response()->json(['message' => 'Delegation deactivated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to deactivate delegation', 'message' => $e->getMessage()], 500);
        }
    }
}
