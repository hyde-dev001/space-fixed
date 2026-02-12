<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class CheckInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Employees can check in for themselves, HR/shop_owner can check in anyone
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
            'check_in_time' => ['nullable', 'date_format:Y-m-d H:i:s'],
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
            'check_in_time.date_format' => 'Invalid check-in time format.',
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

        // Set check-in time to now if not provided
        if (!$this->has('check_in_time')) {
            $this->merge(['check_in_time' => Carbon::now()->format('Y-m-d H:i:s')]);
        }

        // Set shop_owner_id
        $this->merge([
            'shop_owner_id' => $this->user()->shop_owner_id,
            'date' => Carbon::parse($this->check_in_time ?? now())->format('Y-m-d'),
        ]);
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if employee can only check in for themselves (unless HR/shop_owner)
            if (!$this->user()->hasAnyRole(['HR', 'shop_owner'])) {
                $employee = \App\Models\Employee::where('shop_owner_id', $this->user()->shop_owner_id)
                    ->where('email', $this->user()->email)
                    ->first();
                
                if ($employee && $this->employee_id != $employee->id) {
                    $validator->errors()->add('employee_id', 'You can only check in for yourself.');
                }
            }

            // Check if employee has already checked in today
            if ($this->has('employee_id')) {
                $today = Carbon::parse($this->check_in_time ?? now())->format('Y-m-d');
                
                $existingAttendance = \App\Models\AttendanceRecord::where('employee_id', $this->employee_id)
                    ->whereDate('date', $today)
                    ->first();

                if ($existingAttendance && $existingAttendance->check_in_time) {
                    $validator->errors()->add('check_in', 'You have already checked in today.');
                }
            }

            // Check if check-in time is not in the future
            if ($this->has('check_in_time')) {
                $checkInTime = Carbon::parse($this->check_in_time);
                if ($checkInTime->isFuture()) {
                    $validator->errors()->add('check_in_time', 'Check-in time cannot be in the future.');
                }
            }
        });
    }
}
