<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidIdentityDocumentImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The identity document upload is invalid.');

            return;
        }

        $path = $value->getRealPath();
        $mime = $path && is_file($path)
            ? (new \finfo(FILEINFO_MIME_TYPE))->file($path)
            : false;

        if (! is_string($mime) || ! array_key_exists($mime, config('identity_verification.upload.mime_extensions', []))) {
            $fail('The identity document must be a JPG, PNG, or WEBP image.');

            return;
        }

        if (! $path || @getimagesize($path) === false) {
            $fail('The identity document is not a readable image.');
        }
    }
}
