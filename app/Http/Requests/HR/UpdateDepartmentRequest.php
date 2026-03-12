<?php

namespace App\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only HR and shop_owner can update departments
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
        $departmentId = $this->route('id'); // Get department ID from route

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('hr_departments', 'name')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->ignore($departmentId)
            ],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('hr_departments', 'code')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->ignore($departmentId)
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
                Rule::exists('hr_departments', 'id')->where('shop_owner_id', $shopOwnerId),
                'not_in:' . $departmentId, // Prevent self-reference
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
            'name.unique' => 'A department with this name already exists.',
            'code.unique' => 'A department with this code already exists.',
            'manager_id.exists' => 'The selected manager does not exist or is not active.',
            'parent_department_id.exists' => 'The selected parent department does not exist.',
            'parent_department_id.not_in' => 'A department cannot be its own parent.',
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
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Prevent circular parent-child relationships
            if ($this->has('parent_department_id') && $this->parent_department_id) {
                $departmentId = $this->route('id');
                
                // Check if the parent is a descendant of the current department
                $parent = \App\Models\Department::find($this->parent_department_id);
                $currentId = $parent->parent_department_id ?? null;
                
                // Traverse up the tree to check for circular reference
                $maxDepth = 10; // Prevent infinite loop
                $depth = 0;
                
                while ($currentId && $depth < $maxDepth) {
                    if ($currentId == $departmentId) {
                        $validator->errors()->add('parent_department_id', 
                            'Cannot create circular parent-child relationship.'
                        );
                        break;
                    }
                    
                    $parent = \App\Models\Department::find($currentId);
                    $currentId = $parent->parent_department_id ?? null;
                    $depth++;
                }
            }
        });
    }
}
