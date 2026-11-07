<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Enums\AdminPage;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class UpdateAdminPageAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('super_admin')->user();

        return $actor instanceof SuperAdmin
            && $actor->role === SuperAdmin::ROLE_SUPER_ADMIN
            && $actor->hasCapability(SuperAdmin::CAP_MANAGE_ADMINISTRATORS);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page_access' => ['present', 'array', 'max:'.count(AdminPage::assignableKeys())],
            'page_access.*' => ['string', Rule::in(AdminPage::assignableKeys())],
        ];
    }
}
