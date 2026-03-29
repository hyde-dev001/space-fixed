<?php

namespace App\Http\Controllers\ERP\Manager;

use App\Http\Controllers\Controller;
use App\Models\SuspensionRequest;
use App\Enums\EmployeeStatus;
use App\Enums\SuspensionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuspensionApprovalController extends Controller
{
    public function index(Request $request)
    {
        $shopOwnerId = $this->resolveManagerShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json(['message' => 'No shop association found.'], 403);
        }

        $statusFilter = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $perPage = max(5, min((int) $request->query('per_page', 10), 100));

        $query = SuspensionRequest::with(['employee', 'requester', 'manager'])
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId) {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            });

        if ($statusFilter === 'pending') {
            $query->where('status', SuspensionStatus::PENDING_MANAGER);
        } elseif ($statusFilter === 'approved') {
            $query->whereIn('status', [SuspensionStatus::PENDING_OWNER, SuspensionStatus::APPROVED, SuspensionStatus::REJECTED_OWNER]);
        } elseif ($statusFilter === 'rejected') {
            $query->where('status', SuspensionStatus::REJECTED_MANAGER);
        }

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                        $employeeQuery
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $requests = $query->latest()->paginate($perPage);

        $requests->getCollection()->transform(function (SuspensionRequest $req) {
            $status = $this->mapStatusForManager($req->status);

            return [
                'id' => $req->id,
                'name' => $this->employeeName($req),
                'email' => $req->employee?->email,
                'reason' => $req->reason,
                'requestedAt' => optional($req->created_at)->toDateTimeString(),
                'status' => $status,
                'approvedBy' => $req->manager?->name ?? $req->manager?->email,
                'approvalDate' => optional($req->manager_reviewed_at)->toDateTimeString(),
                'approvalNote' => $req->manager_note,
                'rejectionReason' => $req->manager_status === 'rejected' ? $req->manager_note : null,
            ];
        });

        $metricsQuery = SuspensionRequest::query()
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId) {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            });

        $pending = (clone $metricsQuery)
            ->where('status', SuspensionStatus::PENDING_MANAGER)
            ->count();

        $approved = (clone $metricsQuery)
            ->whereIn('status', [SuspensionStatus::PENDING_OWNER, SuspensionStatus::APPROVED, SuspensionStatus::REJECTED_OWNER])
            ->count();

        $rejected = (clone $metricsQuery)
            ->where('status', SuspensionStatus::REJECTED_MANAGER)
            ->count();

        return response()->json([
            'data' => $requests,
            'metrics' => [
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
                'total' => $pending + $approved + $rejected,
            ],
        ]);
    }

    public function show($id)
    {
        $shopOwnerId = $this->resolveManagerShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json(['message' => 'No shop association found.'], 403);
        }

        $req = SuspensionRequest::with(['employee', 'requester', 'manager'])
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId) {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($id);

        return response()->json([
            'id' => $req->id,
            'name' => $this->employeeName($req),
            'email' => $req->employee?->email,
            'reason' => $req->reason,
            'requestedAt' => optional($req->created_at)->toDateTimeString(),
            'status' => $this->mapStatusForManager($req->status),
            'approvedBy' => $req->manager?->name ?? $req->manager?->email,
            'approvalDate' => optional($req->manager_reviewed_at)->toDateTimeString(),
            'approvalNote' => $req->manager_note,
            'rejectionReason' => $req->manager_status === 'rejected' ? $req->manager_note : null,
        ]);
    }

    public function review(Request $request, $id)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:500',
        ]);

        $reviewNote = isset($validated['note']) ? trim((string) $validated['note']) : '';

        if ($validated['action'] === 'reject' && mb_strlen($reviewNote) < 3) {
            return response()->json([
                'message' => 'Rejection note must be at least 3 characters.',
            ], 422);
        }

        $shopOwnerId = $this->resolveManagerShopOwnerId();
        if (!$shopOwnerId) {
            return response()->json(['message' => 'No shop association found.'], 403);
        }

        $req = SuspensionRequest::with('employee')
            ->whereHas('employee', function ($employeeQuery) use ($shopOwnerId) {
                $employeeQuery->where('shop_owner_id', $shopOwnerId);
            })
            ->findOrFail($id);

        if ($req->status !== SuspensionStatus::PENDING_MANAGER) {
            return response()->json(['message' => 'This request is not pending manager review.'], 422);
        }

        $req->manager_id = Auth::guard('user')->id();
        $req->manager_note = $reviewNote !== '' ? $reviewNote : null;
        $req->manager_reviewed_at = now();

        if ($validated['action'] === 'approve') {
            $req->manager_status = 'approved';
            $req->status = SuspensionStatus::PENDING_OWNER;
        } else {
            $req->manager_status = 'rejected';
            $req->status = SuspensionStatus::REJECTED_MANAGER;

            // Re-enable employee access when request is rejected by manager.
            if ($req->employee) {
                $req->employee->update([
                    'status' => EmployeeStatus::ACTIVE,
                    'suspension_reason' => null,
                ]);
            }
        }

        $req->save();

        return response()->json([
            'message' => $validated['action'] === 'approve'
                ? 'Suspension request approved and forwarded to shop owner.'
                : 'Suspension request rejected.',
        ]);
    }

    private function mapStatusForManager(SuspensionStatus $status): string
    {
        return match ($status) {
            SuspensionStatus::PENDING_MANAGER => 'pending',
            SuspensionStatus::REJECTED_MANAGER => 'rejected',
            default => 'approved',
        };
    }

    private function resolveManagerShopOwnerId(): ?int
    {
        $manager = Auth::guard('user')->user();
        $shopOwnerId = (int) ($manager?->shop_owner_id ?? 0);

        return $shopOwnerId > 0 ? $shopOwnerId : null;
    }

    private function employeeName(SuspensionRequest $req): string
    {
        $employee = $req->employee;
        if (!$employee) return '';
        $first = $employee->first_name ?? $employee->firstName ?? '';
        $last = $employee->last_name ?? $employee->lastName ?? '';
        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : ($employee->name ?? $employee->email ?? '');
    }
}
