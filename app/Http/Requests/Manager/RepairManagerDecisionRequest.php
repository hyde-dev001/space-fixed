<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

final class RepairManagerDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route capability and ManagerRepairService perform the
        // authoritative authorization and tenant-scope checks.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'replacement_repairer_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
