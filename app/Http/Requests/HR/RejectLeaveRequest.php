<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

class RejectLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR, shop_owner, or managers can reject leave
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
            'approver_remarks' => ['required', 'string', 'min:10', 'max:500'],
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
            'approver_remarks.required' => 'Please provide a reason for rejection.',
            'approver_remarks.min' => 'Please provide a detailed reason (minimum 10 characters).',
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
                $validator->errors()->add('status', 'Only pending leave requests can be rejected.');
            }
        });
    }
}
