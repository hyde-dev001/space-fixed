<?php

namespace App\Http\Requests;

use App\Models\SupplierAdjustment;
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
            'items.*.replacement_for_adjustment_id' => ['nullable', 'integer'],
            'items.*.reason_category' => ['nullable', 'string', Rule::in(SupplierAdjustment::REASON_CATEGORIES)],
            'items.*.inventory_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.defect_evidence' => ['nullable', 'array', 'max:5'],
            'items.*.defect_evidence.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
            'items.*.size_quantities' => ['nullable', 'array'],
            'items.*.size_quantities.*.inventory_size_id' => ['required', 'integer', 'distinct'],
            'items.*.size_quantities.*.received_quantity' => ['required', 'integer', 'min:0'],
            'items.*.size_quantities.*.defective_quantity' => ['required', 'integer', 'min:0'],
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
                if ((int) ($item['defective_quantity'] ?? 0) > 0) {
                    if (! filled($item['reason_category'] ?? null)) {
                        $validator->errors()->add("items.{$index}.reason_category", 'A defect category is required.');
                    }
                    if (! filled($item['inventory_notes'] ?? null)) {
                        $validator->errors()->add("items.{$index}.inventory_notes", 'Defect notes are required.');
                    }
                    $evidence = $this->file("items.{$index}.defect_evidence", []);
                    if (! is_array($evidence) || count($evidence) < 1) {
                        $validator->errors()->add("items.{$index}.defect_evidence", 'At least one defect image is required.');
                    }
                }
                foreach ($item['size_quantities'] ?? [] as $sizeIndex => $size) {
                    if ((int) ($size['defective_quantity'] ?? 0) > (int) ($size['received_quantity'] ?? 0)) {
                        $validator->errors()->add("items.{$index}.size_quantities.{$sizeIndex}.defective_quantity", 'Defective quantity cannot exceed received quantity.');
                    }
                }
            }
        }];
    }
}
