<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ERP\HR\LeaveController as CanonicalLeaveController;
use App\Models\HR\LeaveBalance;
use App\Models\HR\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Compatibility facade for the original /api/leave route family.
 *
 * Leave lifecycle behavior belongs to the HR controller and service. Keeping
 * this facade thin prevents the legacy routes from creating a second status,
 * balance, authorization, or cancellation implementation.
 */
final class LeaveController extends Controller
{
    private function canonical(): CanonicalLeaveController
    {
        return app(CanonicalLeaveController::class);
    }

    public function index(Request $request)
    {
        return $this->canonical()->index($request);
    }

    public function pending(Request $request)
    {
        return $this->canonical()->getPending($request);
    }

    public function store(Request $request)
    {
        return $this->canonical()->store($request);
    }

    public function approve(Request $request, $id)
    {
        return $this->canonical()->approve($request, $id);
    }

    public function reject(Request $request, $id)
    {
        if (!$request->filled('reason') && $request->filled('rejection_reason')) {
            $request->merge(['reason' => $request->string('rejection_reason')->toString()]);
        }

        return $this->canonical()->reject($request, $id);
    }

    public function show($id)
    {
        return $this->canonical()->show(request(), $id);
    }

    public function cancel($id)
    {
        return $this->canonical()->cancelOwn(request(), $id);
    }

    public function statistics(Request $request, $employeeId)
    {
        $user = Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $shopOwnerId = $this->resolveShopOwnerId($user);
        if (!$shopOwnerId) {
            return response()->json(['error' => 'No shop association found'], 403);
        }

        $year = (int) $request->get('year', now()->year);
        $balances = LeaveBalance::forEmployee((int) $employeeId)
            ->forShopOwner($shopOwnerId)
            ->forYear($year)
            ->get()
            ->keyBy('year');
        $stats = LeaveRequest::forShopOwner($shopOwnerId)
            ->where('employee_id', $employeeId)
            ->whereYear('created_at', $year)
            ->selectRaw('leave_type, status, COUNT(*) as count, SUM(no_of_days) as total_days')
            ->groupBy('leave_type', 'status')
            ->get()
            ->groupBy('leave_type');

        return response()->json([
            'year' => $year,
            'balances' => $balances,
            'statistics' => $stats,
        ]);
    }

    private function resolveShopOwnerId(User $user): ?int
    {
        $role = strtoupper(str_replace(['_', '-'], ' ', trim((string) $user->role)));

        return $role === 'SHOP OWNER'
            ? (int) $user->id
            : ($user->shop_owner_id ? (int) $user->shop_owner_id : null);
    }
}
