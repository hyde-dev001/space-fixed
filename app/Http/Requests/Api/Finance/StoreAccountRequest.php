<?php

namespace App\Http\Requests\Api\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'code' => 'required|string|max:50|unique:finance_accounts,code',
            'name' => 'required|string|max:191',
            'type' => 'required|in:Asset,Liability,Equity,Revenue,Expense',
            // accept either case since seeders and existing data may use 'Debit'/'Credit'
            'normal_balance' => 'nullable|in:Debit,Credit,debit,credit',
            'group' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer|exists:finance_accounts,id',
            'active' => 'sometimes|boolean',
            'shop_id' => 'nullable|integer',
            'meta' => 'nullable|array'
        ];
    }
}
