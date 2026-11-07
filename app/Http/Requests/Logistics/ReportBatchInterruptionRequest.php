<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ReportBatchInterruptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = Auth::guard('user')->user();
        return $actor && $actor->can('operate-logistics-deliveries') && $actor->can('update-logistics-status');
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'notes' => ['required', 'string', 'max:1000'],
            'photo_files' => ['required', 'array', 'min:1', 'max:5'],
            'photo_files.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240']];
    }
}
