<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyMfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'The verification code is invalid.',
            'code.string' => 'The verification code is invalid.',
            'code.max' => 'The verification code is invalid.',
        ];
    }
}
