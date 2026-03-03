<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryQuantityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->shop_owner_id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'available_quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'movement_type' => 'required|in:adjustment,stock_in,stock_out'
        ];
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'available_quantity.required' => 'Quantity is required.',
            'available_quantity.integer' => 'Quantity must be a whole number.',
            'available_quantity.min' => 'Quantity cannot be negative.',
            'movement_type.required' => 'Movement type is required.',
            'movement_type.in' => 'Invalid movement type. Must be adjustment, stock_in, or stock_out.',
        ];
    }
    
    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'available_quantity' => 'quantity',
            'movement_type' => 'movement type',
        ];
    }
    
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default movement type if not provided
        if (!$this->has('movement_type')) {
            $this->merge([
                'movement_type' => 'adjustment'
            ]);
        }
    }
}
