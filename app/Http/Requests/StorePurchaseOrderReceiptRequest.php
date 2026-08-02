<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseOrderReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $purchaseOrderId = (int) $this->route('id');

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $purchaseOrderId),
            ],
            'items.*.received_quantity' => ['required', 'integer', 'min:0'],
            'items.*.defective_quantity' => ['required', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (collect($this->input('items', []))->sum(fn ($item) => (int) ($item['received_quantity'] ?? 0)) < 1) {
                $validator->errors()->add('items', 'At least one received unit is required.');
            }

            foreach ($this->input('items', []) as $index => $item) {
                if ((int) ($item['defective_quantity'] ?? 0) > (int) ($item['received_quantity'] ?? 0)) {
                    $validator->errors()->add("items.{$index}.defective_quantity", 'Defective quantity cannot exceed received quantity.');
                }
            }
        }];
    }
}
