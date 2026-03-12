<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplenishmentRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'product_name' => 'required|string|max:255',
            'sku_code' => 'required|string|max:100',
            'quantity_needed' => 'required|integer|min:1',
            'priority' => 'required|in:high,medium,low',
            'notes' => 'required|string|min:10|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'inventory_item_id.required' => 'Inventory item is required.',
            'inventory_item_id.exists' => 'Selected inventory item does not exist.',
            'product_name.required' => 'Product name is required.',
            'product_name.max' => 'Product name cannot exceed 255 characters.',
            'sku_code.required' => 'SKU code is required.',
            'sku_code.max' => 'SKU code cannot exceed 100 characters.',
            'quantity_needed.required' => 'Quantity needed is required.',
            'quantity_needed.min' => 'Quantity needed must be at least 1.',
            'priority.required' => 'Priority is required.',
            'priority.in' => 'Priority must be high, medium, or low.',
            'notes.required' => 'Notes are required.',
            'notes.min' => 'Notes must be at least 10 characters.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }
}
