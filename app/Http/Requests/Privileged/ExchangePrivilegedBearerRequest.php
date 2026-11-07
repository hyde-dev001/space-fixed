<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use Illuminate\Foundation\Http\FormRequest;

final class ExchangePrivilegedBearerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.required' => 'The security link is invalid or expired.',
            'token.string' => 'The security link is invalid or expired.',
            'token.max' => 'The security link is invalid or expired.',
        ];
    }
}
