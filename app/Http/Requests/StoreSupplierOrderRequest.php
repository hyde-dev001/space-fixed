<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierOrderRequest extends FormRequest
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
        $rules = [
            'supplier_id' => 'required|exists:suppliers,id',
            'po_number' => 'nullable|string|max:100',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after:order_date',
            'total_amount' => 'nullable|numeric|min:0|max:99999999.99',
            'currency' => 'nullable|string|size:3',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'remarks' => 'nullable|string|max:2000',
            
            // Order items
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.sku' => 'nullable|string|max:100',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.notes' => 'nullable|string|max:500',
        ];
        
        // If updating, make po_number unique except for current record
        if ($this->route('id')) {
            $rules['po_number'] = 'nullable|string|max:100|unique:supplier_orders,po_number,' . $this->route('id');
        } else {
            $rules['po_number'] = 'nullable|string|max:100|unique:supplier_orders,po_number';
        }
        
        return $rules;
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Please select a supplier.',
            'supplier_id.exists' => 'Selected supplier does not exist.',
            'po_number.unique' => 'This PO number already exists.',
            'order_date.required' => 'Order date is required.',
            'expected_delivery_date.after' => 'Expected delivery date must be after order date.',
            'items.required' => 'At least one item is required.',
            'items.min' => 'At least one item is required.',
            'items.*.product_name.required' => 'Product name is required for all items.',
            'items.*.quantity.required' => 'Quantity is required for all items.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'items.*.unit_price.min' => 'Price cannot be negative.',
        ];
    }
    
    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'po_number' => 'PO number',
            'order_date' => 'order date',
            'expected_delivery_date' => 'expected delivery date',
            'total_amount' => 'total amount',
            'payment_status' => 'payment status',
            'items.*.product_name' => 'product name',
            'items.*.quantity' => 'quantity',
            'items.*.unit_price' => 'unit price',
        ];
    }
    
    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate that supplier belongs to the current shop owner
            if ($this->supplier_id) {
                $supplier = \App\Models\Supplier::where('id', $this->supplier_id)
                    ->where('shop_owner_id', $this->user()->shop_owner_id)
                    ->first();
                
                if (!$supplier) {
                    $validator->errors()->add('supplier_id', 'The selected supplier does not belong to your shop.');
                }
            }
            
            // Validate that inventory items belong to the current shop owner
            if ($this->items) {
                foreach ($this->items as $index => $item) {
                    if (isset($item['inventory_item_id'])) {
                        $inventoryItem = \App\Models\InventoryItem::where('id', $item['inventory_item_id'])
                            ->where('shop_owner_id', $this->user()->shop_owner_id)
                            ->first();
                        
                        if (!$inventoryItem) {
                            $validator->errors()->add(
                                "items.{$index}.inventory_item_id",
                                'The selected inventory item does not belong to your shop.'
                            );
                        }
                    }
                }
            }
        });
    }
}
