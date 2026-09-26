<?php

namespace App\Services\Finance;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class XenditClient
{
    public function request(string $secretKey): PendingRequest
    {
        return Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->withHeaders([
                'Api-version' => (string) config('services.xendit.api_version', '2025-09-01'),
            ])
            ->connectTimeout(5)
            ->timeout(15);
    }

    public function endpoint(string $path): string
    {
        return rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/') . $path;
    }
}
