<?php

namespace App\Http\Controllers\ERP\Manager;

use App\Http\Controllers\Controller;
use App\Models\SuspensionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuspensionApprovalController extends Controller
{
    public function index(Request $request)
    {
        $statusFilter = $request->query('status');

        $query = SuspensionRequest::with(['employee', 'requester', 'manager']);

        if ($statusFilter === 'pending') {
            $query->where('status', 'pending_manager');
        } elseif ($statusFilter === 'approved') {
            $query->whereIn('status', ['pending_owner', 'approved', 'rejected_owner']);
        } elseif ($statusFilter === 'rejected') {
            $query->where('status', 'rejected_manager');
        }

        $requests = $query->latest()->get()->map(function (SuspensionRequest $req) {
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

        return response()->json(['data' => $requests]);
    }

    public function show($id)
    {
        $req = SuspensionRequest::with(['employee', 'requester', 'manager'])->findOrFail($id);

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
            'note' => 'nullable|string|min:3',
        ]);

        $req = SuspensionRequest::with('employee')->findOrFail($id);

        if ($req->status !== 'pending_manager') {
            return response()->json(['message' => 'This request is not pending manager review.'], 422);
        }

        $req->manager_id = Auth::id();
        $req->manager_note = $validated['note'] ?? null;
        $req->manager_reviewed_at = now();

        if ($validated['action'] === 'approve') {
            $req->manager_status = 'approved';
            $req->status = 'pending_owner';
        } else {
            $req->manager_status = 'rejected';
            $req->status = 'rejected_manager';
        }

        $req->save();

        return response()->json([
            'message' => $validated['action'] === 'approve'
                ? 'Suspension request approved and forwarded to shop owner.'
                : 'Suspension request rejected.',
        ]);
    }

    private function mapStatusForManager(string $status): string
    {
        return match ($status) {
            'pending_manager' => 'pending',
            'rejected_manager' => 'rejected',
            default => 'approved',
        };
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
