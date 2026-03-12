<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR, shop_owner, or managers can approve leave
        if ($this->user()->hasAnyRole(['HR', 'shop_owner'])) {
            return true;
        }

        // Check if user is a manager of the employee requesting leave
        $leaveRequest = \App\Models\LeaveRequest::find($this->route('id'));
        
        if (!$leaveRequest) {
            return false;
        }

        $employee = $leaveRequest->employee;
        
        // Check if current user is the manager
        if ($employee && $employee->manager_id) {
            $manager = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                ->where('email', $this->user()->email)
                ->first();
            
            return $manager && $manager->id === $employee->manager_id;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approver_remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approver_remarks.max' => 'Remarks cannot exceed 500 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $leaveRequest = \App\Models\LeaveRequest::find($this->route('id'));

            if (!$leaveRequest) {
                $validator->errors()->add('leave_request', 'Leave request not found.');
                return;
            }

            // Check if leave belongs to same shop
            if ($leaveRequest->shop_owner_id !== $this->user()->shop_owner_id) {
                $validator->errors()->add('leave_request', 'Unauthorized access to this leave request.');
            }

            // Check if leave is in pending status
            if ($leaveRequest->status !== 'pending') {
                $validator->errors()->add('status', 'Only pending leave requests can be approved.');
            }

            // Check leave balance (if applicable)
            if (in_array($leaveRequest->leave_type, ['annual', 'sick', 'casual'])) {
                $balance = \App\Models\LeaveBalance::where('employee_id', $leaveRequest->employee_id)
                    ->where('leave_type', $leaveRequest->leave_type)
                    ->where('year', now()->year)
                    ->first();

                if (!$balance) {
                    $validator->errors()->add('balance', 'Employee has no leave balance record for this leave type.');
                } elseif (($balance->allocated_days - $balance->used_days) < $leaveRequest->days) {
                    $validator->errors()->add('balance', 
                        sprintf('Insufficient leave balance. Available: %.1f days, Requested: %.1f days', 
                            $balance->allocated_days - $balance->used_days, 
                            $leaveRequest->days
                        )
                    );
                }
            }
        });
    }
}
