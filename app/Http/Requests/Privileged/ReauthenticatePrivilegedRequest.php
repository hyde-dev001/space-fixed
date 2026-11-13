<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use Illuminate\Foundation\Http\FormRequest;

final class ReauthenticatePrivilegedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'intended' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Reauthentication failed.',
            'password.string' => 'Reauthentication failed.',
            'password.max' => 'Reauthentication failed.',
            'code.required' => 'Reauthentication failed.',
            'code.string' => 'Reauthentication failed.',
            'code.max' => 'Reauthentication failed.',
            'intended.string' => 'Reauthentication failed.',
            'intended.max' => 'Reauthentication failed.',
        ];
    }
}
