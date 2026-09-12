<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class InviteAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('super_admin')->user();

        return $actor instanceof SuperAdmin
            && $actor->hasCapability(SuperAdmin::CAP_MANAGE_ADMINISTRATORS);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:2', 'max:255'],
            'last_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('super_admins', 'email')],
            'phone' => ['required', 'digits_between:10,20'],
            'role' => ['required', 'string', Rule::in([
                SuperAdmin::ROLE_ADMIN,
                SuperAdmin::ROLE_SUPER_ADMIN,
            ])],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ];
    }
}
