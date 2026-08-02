<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestRequest extends FormRequest
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
        $shopOwnerId = (int) $this->user()->shop_owner_id;

        return [
            'stock_request_id' => [
                'required',
                'integer',
                Rule::exists('stock_request_approvals', 'id')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('status', 'accepted'),
                Rule::unique('purchase_requests', 'stock_request_id'),
            ],
            'product_name' => 'required|string|max:255',
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('shop_owner_id', $shopOwnerId)],
            'inventory_item_id' => ['nullable', Rule::exists('inventory_items', 'id')->where('shop_owner_id', $shopOwnerId)],
            'requested_size' => 'nullable|string|max:20',
            'requested_color' => 'nullable|string|max:50',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'required|numeric|min:0',
            'priority' => 'required|in:high,medium,low',
            'justification' => 'required|string|min:10',
            'submit_to_finance' => 'boolean',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'stock_request_id.exists' => 'Selected stock request no longer exists.',
            'stock_request_id.required' => 'An accepted stock request is required.',
            'stock_request_id.unique' => 'This stock request already has a purchase request.',
            'product_name.required' => 'Product name is required.',
            'supplier_id.required' => 'Supplier is required.',
            'supplier_id.exists' => 'Selected supplier does not exist.',
            'quantity.required' => 'Quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'unit_cost.required' => 'Unit cost is required.',
            'unit_cost.min' => 'Unit cost must be a positive number.',
            'priority.required' => 'Priority is required.',
            'priority.in' => 'Priority must be high, medium, or low.',
            'justification.required' => 'Justification is required.',
            'justification.min' => 'Justification must be at least 10 characters.',
        ];
    }
}
