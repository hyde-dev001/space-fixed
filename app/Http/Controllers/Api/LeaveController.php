<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HR\LeaveRequest;
use App\Models\HR\LeaveBalance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LeaveController extends Controller
{
    /**
     * Get all leave requests for the current user's shop
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            $query = LeaveRequest::where('shop_owner_id', $shopOwnerId)
                ->with(['employee', 'approver']);
            
            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by employee (for staff viewing their own)
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }
            
            // Filter by leave type
            if ($request->filled('leave_type')) {
                $query->where('leave_type', $request->leave_type);
            }
            
            // Filter by date range
            if ($request->filled('start_date')) {
                $query->where('start_date', '>=', $request->start_date);
            }
            
            if ($request->filled('end_date')) {
                $query->where('end_date', '<=', $request->end_date);
            }
            
            $leaves = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));
            
            return response()->json($leaves);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch leave requests: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave requests'], 500);
        }
    }
    
    /**
     * Get pending leave requests for manager approval
     */
    public function pending(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            // Only managers can view pending approvals
            if (!in_array($user->role, ['MANAGER', 'FINANCE_MANAGER', 'SUPER_ADMIN', 'shop_owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $pendingLeaves = LeaveRequest::where('shop_owner_id', $shopOwnerId)
                ->where('status', 'pending')
                ->with(['employee' => function($query) {
                    $query->select('id', 'name', 'email', 'position');
                }])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function($leave) {
                    return [
                        'id' => $leave->id,
                        'employee' => [
                            'id' => $leave->employee->id,
                            'name' => $leave->employee->name,
                            'email' => $leave->employee->email,
                            'position' => $leave->employee->position,
                        ],
                        'leave_type' => $leave->leave_type,
                        'leave_type_label' => LeaveRequest::LEAVE_TYPES[$leave->leave_type] ?? $leave->leave_type,
                        'start_date' => $leave->start_date->format('Y-m-d'),
                        'end_date' => $leave->end_date->format('Y-m-d'),
                        'no_of_days' => $leave->no_of_days,
                        'reason' => $leave->reason,
                        'status' => $leave->status,
                        'created_at' => $leave->created_at->toIso8601String(),
                        'days_pending' => Carbon::parse($leave->created_at)->diffInDays(now()),
                    ];
                });
            
            return response()->json([
                'pending_count' => $pendingLeaves->count(),
                'requests' => $pendingLeaves
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch pending leave requests: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch pending leave requests'], 500);
        }
    }
    
    /**
     * Create a new leave request
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'leave_type' => 'required|in:vacation,sick,personal,maternity,paternity,unpaid',
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'required|string|max:1000',
            ]);
            
            $user = Auth::guard('user')->user();
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            // Verify employee belongs to shop
            $employee = Employee::where('id', $validated['employee_id'])
                ->where('shop_owner_id', $shopOwnerId)
                ->first();
                
            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }
            
            // Calculate number of days
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
            $noOfDays = $startDate->diffInDays($endDate) + 1;
            
            // Check for overlapping leave requests
            $overlapping = LeaveRequest::where('employee_id', $validated['employee_id'])
                ->where('status', '!=', 'rejected')
                ->where(function($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate])
                        ->orWhere(function($q) use ($startDate, $endDate) {
                            $q->where('start_date', '<=', $startDate)
                              ->where('end_date', '>=', $endDate);
                        });
                })
                ->exists();
                
            if ($overlapping) {
                return response()->json([
                    'error' => 'You already have a leave request for these dates'
                ], 422);
            }
            
            // Check leave balance if applicable
            if (in_array($validated['leave_type'], ['vacation', 'sick'])) {
                $balance = LeaveBalance::where('employee_id', $validated['employee_id'])
                    ->where('leave_type', $validated['leave_type'])
                    ->where('year', date('Y'))
                    ->first();
                    
                if ($balance && $balance->remaining_days < $noOfDays) {
                    return response()->json([
                        'error' => 'Insufficient leave balance. Available: ' . $balance->remaining_days . ' days'
                    ], 422);
                }
            }
            
            DB::beginTransaction();
            
            $leaveRequest = LeaveRequest::create([
                'employee_id' => $validated['employee_id'],
                'shop_owner_id' => $shopOwnerId,
                'leave_type' => $validated['leave_type'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'no_of_days' => $noOfDays,
                'reason' => $validated['reason'],
                'status' => 'pending',
            ]);
            
            DB::commit();
            
            // TODO: Send notification to manager
            
            return response()->json([
                'message' => 'Leave request submitted successfully',
                'leave_request' => $leaveRequest->load('employee')
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create leave request'], 500);
        }
    }
    
    /**
     * Approve a leave request
     */
    public function approve(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            // Check if user has permission to approve
            if (!in_array($user->role, ['MANAGER', 'FINANCE_MANAGER', 'SUPER_ADMIN', 'shop_owner'])) {
                return response()->json(['error' => 'Unauthorized to approve leave requests'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();
                
            if (!$leaveRequest) {
                return response()->json(['error' => 'Leave request not found'], 404);
            }
            
            if ($leaveRequest->status !== 'pending') {
                return response()->json([
                    'error' => 'Leave request has already been ' . $leaveRequest->status
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Update leave request
            $leaveRequest->status = 'approved';
            $leaveRequest->approved_by = $user->id;
            $leaveRequest->approval_date = now();
            $leaveRequest->save();
            
            // Deduct from leave balance if applicable
            if (in_array($leaveRequest->leave_type, ['vacation', 'sick'])) {
                $balance = LeaveBalance::where('employee_id', $leaveRequest->employee_id)
                    ->where('leave_type', $leaveRequest->leave_type)
                    ->where('year', date('Y'))
                    ->first();
                    
                if ($balance) {
                    $balance->used_days += $leaveRequest->no_of_days;
                    $balance->remaining_days = $balance->total_days - $balance->used_days;
                    $balance->save();
                }
            }
            
            DB::commit();
            
            // TODO: Send notification to employee
            
            return response()->json([
                'message' => 'Leave request approved successfully',
                'leave_request' => $leaveRequest->load(['employee', 'approver'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to approve leave request'], 500);
        }
    }
    
    /**
     * Reject a leave request
     */
    public function reject(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'rejection_reason' => 'required|string|max:500',
            ]);
            
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            // Check if user has permission to reject
            if (!in_array($user->role, ['MANAGER', 'FINANCE_MANAGER', 'SUPER_ADMIN', 'shop_owner'])) {
                return response()->json(['error' => 'Unauthorized to reject leave requests'], 403);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();
                
            if (!$leaveRequest) {
                return response()->json(['error' => 'Leave request not found'], 404);
            }
            
            if ($leaveRequest->status !== 'pending') {
                return response()->json([
                    'error' => 'Leave request has already been ' . $leaveRequest->status
                ], 422);
            }
            
            $leaveRequest->status = 'rejected';
            $leaveRequest->approved_by = $user->id;
            $leaveRequest->approval_date = now();
            $leaveRequest->rejection_reason = $validated['rejection_reason'];
            $leaveRequest->save();
            
            // TODO: Send notification to employee
            
            return response()->json([
                'message' => 'Leave request rejected',
                'leave_request' => $leaveRequest->load(['employee', 'approver'])
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to reject leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to reject leave request'], 500);
        }
    }
    
    /**
     * Get a single leave request
     */
    public function show($id)
    {
        try {
            $user = Auth::guard('user')->user();
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->with(['employee', 'approver'])
                ->first();
                
            if (!$leaveRequest) {
                return response()->json(['error' => 'Leave request not found'], 404);
            }
            
            return response()->json($leaveRequest);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave request'], 500);
        }
    }
    
    /**
     * Cancel a leave request (employee only, if pending)
     */
    public function cancel($id)
    {
        try {
            $user = Auth::guard('user')->user();
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $leaveRequest = LeaveRequest::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();
                
            if (!$leaveRequest) {
                return response()->json(['error' => 'Leave request not found'], 404);
            }
            
            if ($leaveRequest->status !== 'pending') {
                return response()->json([
                    'error' => 'Can only cancel pending leave requests'
                ], 422);
            }
            
            $leaveRequest->delete();
            
            return response()->json([
                'message' => 'Leave request cancelled successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to cancel leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to cancel leave request'], 500);
        }
    }
    
    /**
     * Get leave statistics for an employee
     */
    public function statistics(Request $request, $employeeId)
    {
        try {
            $user = Auth::guard('user')->user();
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $year = $request->get('year', date('Y'));
            
            // Get leave balances
            $balances = LeaveBalance::where('employee_id', $employeeId)
                ->where('year', $year)
                ->get()
                ->keyBy('leave_type');
            
            // Get leave request statistics
            $stats = LeaveRequest::where('employee_id', $employeeId)
                ->where('shop_owner_id', $shopOwnerId)
                ->whereYear('created_at', $year)
                ->selectRaw('
                    leave_type,
                    status,
                    COUNT(*) as count,
                    SUM(no_of_days) as total_days
                ')
                ->groupBy('leave_type', 'status')
                ->get()
                ->groupBy('leave_type');
            
            return response()->json([
                'year' => $year,
                'balances' => $balances,
                'statistics' => $stats,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch leave statistics: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave statistics'], 500);
        }
    }
}
