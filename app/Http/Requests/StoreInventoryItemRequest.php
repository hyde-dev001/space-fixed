<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryItemRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'required|in:shoes,accessories,care_products,cleaning_materials,packaging,repair_materials',
            'brand' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:1000',
            'unit' => 'nullable|string|max:50',
            'available_quantity' => 'required|integer|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'price' => 'nullable|numeric|min:0|max:9999999.99',
            'cost_price' => 'nullable|numeric|min:0|max:9999999.99',
            'weight' => 'nullable|numeric|min:0|max:99999.99',
            
            // Sizes (for shoes, accessories)
            'sizes' => 'nullable|array',
            'sizes.*.size' => 'required|string|max:50',
            'sizes.*.quantity' => 'required|integer|min:0',
            
            // Color variants (for shoes)
            'color_variants' => 'nullable|array',
            'color_variants.*.color_name' => 'required|string|max:100',
            'color_variants.*.color_code' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'color_variants.*.quantity' => 'required|integer|min:0',
            'color_variants.*.sku_suffix' => 'nullable|string|max:50',
            
            // Images
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ];
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required.',
            'sku.unique' => 'This SKU already exists.',
            'category.required' => 'Please select a category.',
            'category.in' => 'Invalid category selected.',
            'available_quantity.required' => 'Quantity is required.',
            'available_quantity.min' => 'Quantity cannot be negative.',
            'color_variants.*.color_code.regex' => 'Color code must be in hex format (e.g., #FF5733).',
            'images.*.image' => 'File must be an image.',
            'images.*.max' => 'Image size cannot exceed 2MB.',
        ];
    }
    
    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'available_quantity' => 'quantity',
            'reorder_level' => 'reorder level',
            'reorder_quantity' => 'reorder quantity',
            'cost_price' => 'cost price',
        ];
    }
}
