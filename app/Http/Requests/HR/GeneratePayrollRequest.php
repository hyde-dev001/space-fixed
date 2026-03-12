<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneratePayrollRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR and shop_owner can generate payroll
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

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'pay_period_month' => [
                'required',
                'string',
                'in:January,February,March,April,May,June,July,August,September,October,November,December'
            ],
            'pay_period_year' => [
                'required',
                'integer',
                'min:2020',
                'max:' . (date('Y') + 1)
            ],
            'pay_period_start' => ['required', 'date'],
            'pay_period_end' => ['required', 'date', 'after_or_equal:pay_period_start'],
            'pay_date' => ['required', 'date', 'after_or_equal:pay_period_end'],
            
            // Earnings
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'gross_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'allowances' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'bonus' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'overtime_pay' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'commission' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            
            // Deductions
            'deductions' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'tax_deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'insurance_deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'pension_deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'loan_deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'other_deductions' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'total_deductions' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            
            // Net Salary
            'net_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            
            // Payment Details
            'payment_method' => ['nullable', 'in:bank_transfer,cash,cheque'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            
            // Additional
            'working_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'present_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'absent_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:744'], // Max hours in a month
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
            'employee_id.required' => 'Employee selection is required.',
            'employee_id.exists' => 'The selected employee does not exist or is not active.',
            'pay_period_month.required' => 'Pay period month is required.',
            'pay_period_month.in' => 'Invalid pay period month.',
            'pay_period_year.required' => 'Pay period year is required.',
            'pay_period_year.min' => 'Pay period year must be 2020 or later.',
            'pay_period_start.required' => 'Pay period start date is required.',
            'pay_period_end.required' => 'Pay period end date is required.',
            'pay_period_end.after_or_equal' => 'Pay period end date must be on or after start date.',
            'pay_date.required' => 'Payment date is required.',
            'pay_date.after_or_equal' => 'Payment date must be on or after pay period end date.',
            'basic_salary.required' => 'Basic salary is required.',
            'basic_salary.numeric' => 'Basic salary must be a number.',
            'gross_salary.required' => 'Gross salary is required.',
            'net_salary.required' => 'Net salary is required.',
            'payment_method.in' => 'Invalid payment method.',
            'working_days.max' => 'Working days cannot exceed 31.',
            'present_days.max' => 'Present days cannot exceed 31.',
            'absent_days.max' => 'Absent days cannot exceed 31.',
            'overtime_hours.max' => 'Overtime hours cannot exceed 744 (maximum hours in a month).',
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
            'pay_period_month' => 'pay period month',
            'pay_period_year' => 'pay period year',
            'pay_period_start' => 'pay period start',
            'pay_period_end' => 'pay period end',
            'pay_date' => 'payment date',
            'basic_salary' => 'basic salary',
            'gross_salary' => 'gross salary',
            'net_salary' => 'net salary',
            'payment_method' => 'payment method',
            'working_days' => 'working days',
            'present_days' => 'present days',
            'absent_days' => 'absent days',
            'overtime_hours' => 'overtime hours',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set shop_owner_id
        $this->merge([
            'shop_owner_id' => $this->user()->shop_owner_id,
            'status' => 'draft', // New payrolls start as draft
        ]);

        // Calculate total deductions if not provided
        if (!$this->has('total_deductions')) {
            $totalDeductions = ($this->tax_deduction ?? 0) 
                + ($this->insurance_deduction ?? 0)
                + ($this->pension_deduction ?? 0)
                + ($this->loan_deduction ?? 0)
                + ($this->other_deductions ?? 0);
            
            $this->merge(['total_deductions' => $totalDeductions]);
        }

        // Calculate net salary if not provided
        if (!$this->has('net_salary') && $this->has('gross_salary')) {
            $netSalary = $this->gross_salary - ($this->total_deductions ?? 0);
            $this->merge(['net_salary' => max(0, $netSalary)]); // Ensure non-negative
        }

        // Calculate gross salary if not provided
        if (!$this->has('gross_salary') && $this->has('basic_salary')) {
            $grossSalary = $this->basic_salary 
                + ($this->allowances ?? 0)
                + ($this->bonus ?? 0)
                + ($this->overtime_pay ?? 0)
                + ($this->commission ?? 0);
            
            $this->merge(['gross_salary' => $grossSalary]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Check if payroll already exists for this employee and period
            if ($this->has('employee_id') && $this->has('pay_period_month') && $this->has('pay_period_year')) {
                $exists = \App\Models\Payroll::where('employee_id', $this->employee_id)
                    ->where('pay_period_month', $this->pay_period_month)
                    ->where('pay_period_year', $this->pay_period_year)
                    ->where('shop_owner_id', $this->user()->shop_owner_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('payroll', 
                        'Payroll already exists for this employee for ' . 
                        $this->pay_period_month . ' ' . $this->pay_period_year
                    );
                }
            }

            // Validate gross salary calculation
            if ($this->has('basic_salary') && $this->has('gross_salary')) {
                $calculatedGross = $this->basic_salary 
                    + ($this->allowances ?? 0)
                    + ($this->bonus ?? 0)
                    + ($this->overtime_pay ?? 0)
                    + ($this->commission ?? 0);

                if (abs($calculatedGross - $this->gross_salary) > 0.01) { // Allow 1 cent difference for rounding
                    $validator->errors()->add('gross_salary', 
                        'Gross salary does not match sum of earnings components. Expected: ' . 
                        number_format($calculatedGross, 2)
                    );
                }
            }

            // Validate net salary calculation
            if ($this->has('gross_salary') && $this->has('net_salary') && $this->has('total_deductions')) {
                $calculatedNet = $this->gross_salary - $this->total_deductions;

                if (abs($calculatedNet - $this->net_salary) > 0.01) {
                    $validator->errors()->add('net_salary', 
                        'Net salary does not match gross salary minus deductions. Expected: ' . 
                        number_format($calculatedNet, 2)
                    );
                }
            }

            // Validate attendance days
            if ($this->has('working_days') && $this->has('present_days') && $this->has('absent_days')) {
                if (($this->present_days + $this->absent_days) > $this->working_days) {
                    $validator->errors()->add('working_days', 
                        'Sum of present and absent days cannot exceed total working days.'
                    );
                }
            }
        });
    }
}
