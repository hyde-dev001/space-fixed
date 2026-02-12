<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class CheckOutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Employees can check out for themselves, HR/shop_owner can check out anyone
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
            'check_out_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'location' => ['nullable', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
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
            'check_out_time.date_format' => 'Invalid check-out time format.',
            'latitude.between' => 'Invalid latitude value.',
            'longitude.between' => 'Invalid longitude value.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // If employee_id not provided, use current user's employee record
        if (!$this->has('employee_id')) {
            $employee = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                ->where('email', $this->user()->email)
                ->first();
            
            if ($employee) {
                $this->merge(['employee_id' => $employee->id]);
            }
        }

        // Set check-out time to now if not provided
        if (!$this->has('check_out_time')) {
            $this->merge(['check_out_time' => Carbon::now()->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if employee can only check out for themselves (unless HR/shop_owner)
            if (!$this->user()->hasAnyRole(['HR', 'shop_owner'])) {
                $employee = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                    ->where('email', $this->user()->email)
                    ->first();
                
                if ($employee && $this->employee_id != $employee->id) {
                    $validator->errors()->add('employee_id', 'You can only check out for yourself.');
                }
            }

            // Check if employee has checked in today
            if ($this->has('employee_id')) {
                $today = Carbon::parse($this->check_out_time ?? now())->format('Y-m-d');
                
                $attendance = \App\Models\AttendanceRecord::where('employee_id', $this->employee_id)
                    ->whereDate('date', $today)
                    ->first();

                if (!$attendance || !$attendance->check_in_time) {
                    $validator->errors()->add('check_out', 'You must check in before checking out.');
                }

                if ($attendance && $attendance->check_out_time) {
                    $validator->errors()->add('check_out', 'You have already checked out today.');
                }

                // Validate check-out time is after check-in time
                if ($attendance && $attendance->check_in_time && $this->has('check_out_time')) {
                    $checkInTime = Carbon::parse($attendance->check_in_time);
                    $checkOutTime = Carbon::parse($this->check_out_time);
                    
                    if ($checkOutTime->lessThanOrEqualTo($checkInTime)) {
                        $validator->errors()->add('check_out_time', 'Check-out time must be after check-in time.');
                    }
                }
            }

            // Check if check-out time is not in the future
            if ($this->has('check_out_time')) {
                $checkOutTime = Carbon::parse($this->check_out_time);
                if ($checkOutTime->isFuture()) {
                    $validator->errors()->add('check_out_time', 'Check-out time cannot be in the future.');
                }
            }
        });
    }
}
