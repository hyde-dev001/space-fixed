<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class CompleteSetupPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'completion_proof' => ['required', 'string', 'max:2048'],
            'password' => [
                'required',
                'string',
                Password::min(12)->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'completion_proof.required' => 'The setup link is invalid or expired.',
            'completion_proof.string' => 'The setup link is invalid or expired.',
            'completion_proof.max' => 'The setup link is invalid or expired.',
            'password.required' => 'The setup password is invalid.',
            'password.confirmed' => 'The setup password is invalid.',
        ];
    }
}
