<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderStatusRequest extends FormRequest
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
            'status' => 'required|in:sent,confirmed,in_transit,delivered,completed',
            'notes' => 'nullable|string|max:1000',
            'actual_delivery_date' => 'nullable|date',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in' => 'Status must be one of: sent, confirmed, in_transit, delivered, completed.',
            'actual_delivery_date.date' => 'Actual delivery date must be a valid date.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }
}
