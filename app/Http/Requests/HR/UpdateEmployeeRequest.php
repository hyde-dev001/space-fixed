<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user has HR or shop_owner role
        return $this->user()->hasAnyRole(['HR', 'shop_owner']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shopOwnerId = $this->user()->shop_owner_id;
        $employeeId = $this->route('id'); // Get employee ID from route parameter

        return [
            // Personal Information
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->ignore($employeeId)
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            
            // Employment Information
            'employee_id' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('employees', 'employee_id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->ignore($employeeId)
            ],
            'department_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('departments', 'id')->where('shop_owner_id', $shopOwnerId)
            ],
            'position' => ['sometimes', 'required', 'string', 'max:100'],
            'hire_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'employment_type' => ['sometimes', 'required', 'in:full_time,part_time,contract,intern'],
            'status' => ['sometimes', 'required', 'in:active,suspended,on_leave'],
            
            // Compensation
            'salary' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            
            // Probation
            'probation_period_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'probation_end_date' => ['nullable', 'date', 'after:hire_date'],
            
            // Manager
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            
            // Additional Information
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'email.unique' => 'An employee with this email already exists in your organization.',
            'employee_id.unique' => 'This employee ID is already in use.',
            'department_id.exists' => 'The selected department does not exist.',
            'employment_type.in' => 'Invalid employment type selected.',
            'salary.numeric' => 'Salary must be a number.',
            'salary.min' => 'Salary cannot be negative.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
            'hire_date.before_or_equal' => 'Hire date cannot be in the future.',
            'probation_end_date.after' => 'Probation end date must be after hire date.',
            'manager_id.exists' => 'The selected manager does not exist or is not active.',
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
            'first_name' => 'first name',
            'last_name' => 'last name',
            'employee_id' => 'employee ID',
            'department_id' => 'department',
            'hire_date' => 'hire date',
            'employment_type' => 'employment type',
            'date_of_birth' => 'date of birth',
            'probation_period_months' => 'probation period',
            'probation_end_date' => 'probation end date',
            'manager_id' => 'manager',
        ];
    }
}
