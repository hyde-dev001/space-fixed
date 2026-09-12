<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
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
            'purchase_request_ids' => ['required', 'array', 'min:1'],
            'purchase_request_ids.*' => ['required', 'integer', 'distinct', Rule::exists('purchase_requests', 'id')->where('shop_owner_id', $shopOwnerId)],
            'expected_delivery_date' => 'nullable|date|after_or_equal:today',
            'payment_terms' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'pr_id' => 'prohibited',
            'supplier_id' => 'prohibited',
            'inventory_item_id' => 'prohibited',
            'product_name' => 'prohibited',
            'requested_size' => 'prohibited',
            'requested_color' => 'prohibited',
            'quantity' => 'prohibited',
            'unit_cost' => 'prohibited',
            'total_cost' => 'prohibited',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'purchase_request_ids.required' => 'At least one purchase request is required.',
            'purchase_request_ids.*.exists' => 'A selected purchase request does not exist.',
            'expected_delivery_date.date' => 'Expected delivery date must be a valid date.',
            'expected_delivery_date.after_or_equal' => 'Expected delivery date cannot be in the past.',
            'payment_terms.required' => 'Payment terms are required.',
            'payment_terms.max' => 'Payment terms cannot exceed 255 characters.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }
}
