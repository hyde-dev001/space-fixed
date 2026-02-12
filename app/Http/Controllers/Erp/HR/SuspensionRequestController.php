<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SuspensionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuspensionRequestController extends Controller
{
    public function index(Request $request)
    {
        $requests = SuspensionRequest::with(['employee', 'requester'])
            ->latest()
            ->get()
            ->map(function (SuspensionRequest $req) {
                return [
                    'id' => $req->id,
                    'employee_id' => $req->employee_id,
                    'name' => $this->employeeName($req->employee),
                    'email' => $req->employee?->email,
                    'position' => $req->employee?->position,
                    'reason' => $req->reason,
                    'evidence' => $req->evidence,
                    'status' => $req->status,
                    'requested_at' => optional($req->created_at)->toDateTimeString(),
                    'requested_by' => $req->requester?->name ?? $req->requester?->email,
                    'manager_status' => $req->manager_status,
                    'manager_note' => $req->manager_note,
                    'manager_name' => $req->manager?->name,
                    'owner_status' => $req->owner_status,
                    'owner_note' => $req->owner_note,
                ];
            });

        return response()->json(['data' => $requests]);
    }

    public function show($id)
    {
        $req = SuspensionRequest::with(['employee', 'requester', 'manager', 'owner'])->findOrFail($id);

        return response()->json([
            'id' => $req->id,
            'employee_id' => $req->employee_id,
            'name' => $this->employeeName($req->employee),
            'email' => $req->employee?->email,
            'position' => $req->employee?->position,
            'reason' => $req->reason,
            'evidence' => $req->evidence,
            'status' => $req->status,
            'requested_at' => optional($req->created_at)->toDateTimeString(),
            'requested_by' => $req->requester?->name ?? $req->requester?->email,
            'manager_status' => $req->manager_status,
            'manager_note' => $req->manager_note,
            'manager_name' => $req->manager?->name,
            'owner_status' => $req->owner_status,
            'owner_note' => $req->owner_note,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'reason' => 'required|string|min:3',
            'evidence' => 'nullable|string',
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $hasPending = SuspensionRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending_manager', 'pending_owner'])
            ->exists();

        if ($hasPending) {
            return response()->json([
                'message' => 'There is already a pending suspension request for this employee.'
            ], 422);
        }

        $req = SuspensionRequest::create([
            'employee_id' => $employee->id,
            'requested_by' => Auth::id(),
            'reason' => $validated['reason'],
            'evidence' => $validated['evidence'] ?? null,
            'status' => 'pending_manager',
            'manager_status' => 'pending',
            'owner_status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Suspension request submitted successfully.',
            'request' => $req,
        ], 201);
    }

    private function employeeName(?Employee $employee): string
    {
        if (!$employee) return '';
        $first = $employee->first_name ?? $employee->firstName ?? '';
        $last = $employee->last_name ?? $employee->lastName ?? '';
        $name = trim($first . ' ' . $last);
        return $name !== '' ? $name : ($employee->name ?? $employee->email ?? '');
    }
}
