<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class StoreLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Employees can request their own leave, HR and shop_owner can request for anyone
        return $this->user()->hasAnyRole(['HR', 'shop_owner', 'employee']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shopOwnerId = $this->user()->shop_owner_id;

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'leave_type' => [
                'required',
                'in:annual,sick,casual,maternity,paternity,unpaid,compensatory,bereavement'
            ],
            'start_date' => [
                'required',
                'date',
                'after_or_equal:today'
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date'
            ],
            'days' => [
                'required',
                'numeric',
                'min:0.5',
                'max:365'
            ],
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000'
            ],
            'remarks' => ['nullable', 'string', 'max:500'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'], // 5MB max
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
            'employee_id.required' => 'Employee selection is required.',
            'employee_id.exists' => 'The selected employee does not exist or is not active.',
            'leave_type.required' => 'Leave type is required.',
            'leave_type.in' => 'Invalid leave type selected.',
            'start_date.required' => 'Leave start date is required.',
            'start_date.after_or_equal' => 'Leave cannot be backdated. Start date must be today or later.',
            'end_date.required' => 'Leave end date is required.',
            'end_date.after_or_equal' => 'End date must be on or after start date.',
            'days.required' => 'Number of leave days is required.',
            'days.min' => 'Minimum leave duration is 0.5 days.',
            'days.max' => 'Maximum leave duration is 365 days.',
            'reason.required' => 'Leave reason is required.',
            'reason.min' => 'Please provide a detailed reason (minimum 10 characters).',
            'reason.max' => 'Reason is too long (maximum 1000 characters).',
            'attachment.file' => 'The attachment must be a valid file.',
            'attachment.max' => 'The attachment size cannot exceed 5MB.',
            'attachment.mimes' => 'The attachment must be a PDF, JPG, JPEG, or PNG file.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'employee',
            'leave_type' => 'leave type',
            'start_date' => 'start date',
            'end_date' => 'end date',
            'days' => 'leave days',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // If employee_id not provided, use current user's employee record
        if (!$this->has('employee_id')) {
            // Find employee record for current user
            $employee = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                ->where('email', $this->user()->email)
                ->first();
            
            if ($employee) {
                $this->merge(['employee_id' => $employee->id]);
            }
        }

        // Automatically set shop_owner_id
        $this->merge([
            'shop_owner_id' => $this->user()->shop_owner_id,
            'status' => 'pending', // All new requests start as pending
        ]);

        // Calculate days if not provided
        if (!$this->has('days') && $this->has('start_date') && $this->has('end_date')) {
            $start = Carbon::parse($this->start_date);
            $end = Carbon::parse($this->end_date);
            $this->merge([
                'days' => $start->diffInDays($end) + 1,
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if employee can only request their own leave (unless HR/shop_owner)
            if (!$this->user()->hasAnyRole(['HR', 'shop_owner'])) {
                $employee = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                    ->where('email', $this->user()->email)
                    ->first();
                
                if ($employee && $this->employee_id != $employee->id) {
                    $validator->errors()->add('employee_id', 'You can only request leave for yourself.');
                }
            }

            // Check for overlapping leave requests
            if ($this->has('employee_id') && $this->has('start_date') && $this->has('end_date')) {
                $overlapping = \App\Models\LeaveRequest::where('employee_id', $this->employee_id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->where(function ($query) {
                        $query->whereBetween('start_date', [$this->start_date, $this->end_date])
                            ->orWhereBetween('end_date', [$this->start_date, $this->end_date])
                            ->orWhere(function ($q) {
                                $q->where('start_date', '<=', $this->start_date)
                                  ->where('end_date', '>=', $this->end_date);
                            });
                    })
                    ->exists();

                if ($overlapping) {
                    $validator->errors()->add('start_date', 'You have an overlapping leave request for these dates.');
                }
            }
        });
    }
}
