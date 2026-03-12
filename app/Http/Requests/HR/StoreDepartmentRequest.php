<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR and shop_owner can create departments
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
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('hr_departments', 'name')->where('shop_owner_id', $shopOwnerId)
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('hr_departments', 'code')->where('shop_owner_id', $shopOwnerId)
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_employees', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'active')
            ],
            'parent_department_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_departments', 'id')->where('shop_owner_id', $shopOwnerId)
            ],
            'location' => ['nullable', 'string', 'max:200'],
            'cost_center' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
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
            'name.required' => 'Department name is required.',
            'name.unique' => 'A department with this name already exists.',
            'code.unique' => 'A department with this code already exists.',
            'manager_id.exists' => 'The selected manager does not exist or is not active.',
            'parent_department_id.exists' => 'The selected parent department does not exist.',
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
            'name' => 'department name',
            'code' => 'department code',
            'manager_id' => 'department manager',
            'parent_department_id' => 'parent department',
            'cost_center' => 'cost center',
            'is_active' => 'active status',
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
        ]);

        // Set is_active to true if not provided
        if (!$this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }

        // Generate code from name if not provided
        if (!$this->has('code') && $this->has('name')) {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $this->name), 0, 10));
            $this->merge(['code' => $code]);
        }
    }
}
