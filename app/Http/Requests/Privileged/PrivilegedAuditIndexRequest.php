<?php

declare(strict_types=1);

namespace App\Http\Requests\Privileged;

use App\Services\PrivilegedAuditVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrivilegedAuditIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        foreach ([
            'event',
            'actor_id',
            'target_type',
            'target_id',
            'correlation_id',
            'date_from',
            'date_to',
            'per_page',
            'page',
        ] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $trimmed[$key] = trim((string) $this->input($key));
            }
        }

        $this->merge($trimmed);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'event' => ['nullable', 'string', Rule::in(PrivilegedAuditVisibility::eventValues())],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'target_type' => ['nullable', 'string', Rule::in(PrivilegedAuditVisibility::targetTypeValues())],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'correlation_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
