<?php

namespace App\Services\Finance;

use App\Models\ShopPaymentIntegration;
use App\Models\Supplier;
use App\Models\SupplierPaymentAttempt;
use App\Models\SupplierPaymentProfile;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class XenditPayoutService
{
    public function verifyCredentials(string $secretKey, ?int $shopId = null): void
    {
        try {
            $response = $this->client($secretKey)->get($this->endpoint('/balance'), [
                'account_type' => 'CASH',
                'currency' => 'PHP',
            ]);
        } catch (Throwable) {
            $this->logFailure('verify_credentials', $shopId, null, null);

            throw new FinanceDomainException(
                'SoleSpace could not reach Xendit from this server. Check the local internet, DNS, or firewall connection and try again.',
                'XENDIT_CONNECTION_FAILED',
                422,
            );
        }

        if (! $response->successful()) {
            $this->logFailure('verify_credentials', $shopId, null, $response);

            throw new FinanceDomainException(
                $this->verificationFailureMessage($response),
                'XENDIT_CONNECTION_FAILED',
                422,
            );
        }
    }

    /** @return array{countries: array<int, array{code: string, name: string, currency: string}>, banks: array<int, array{channel_code: string, channel_name: string, channel_category: string, currency: string}>, e_wallets: array<int, array{channel_code: string, channel_name: string, channel_category: string, currency: string}>} */
    public function getPayoutChannels(string $secretKey, ?int $shopId = null): array
    {
        if (trim($secretKey) === '') {
            throw new FinanceDomainException(
                'Xendit supplier payouts are not configured for this shop.',
                'XENDIT_NOT_CONFIGURED',
                422,
            );
        }

        try {
            $response = $this->client($secretKey)
                ->retry(2, 200)
                ->get($this->endpoint('/payouts_channels'), ['currency' => 'PHP']);
        } catch (Throwable) {
            $this->logFailure('list_payout_channels', $shopId, null, null);

            throw new FinanceDomainException(
                'SoleSpace could not load Xendit payout channels. Check the connection and try again.',
                'XENDIT_CHANNELS_UNAVAILABLE',
                502,
            );
        }

        if (! $response->successful()) {
            $this->logFailure('list_payout_channels', $shopId, null, $response);

            throw new FinanceDomainException(
                $response->status() === 401
                    ? 'Xendit rejected this secret key. Check the Test/Live key and try again.'
                    : 'Xendit could not load payout channels. Check the key permissions and try again.',
                $response->status() === 401 ? 'XENDIT_KEY_REJECTED' : 'XENDIT_CHANNELS_UNAVAILABLE',
                422,
            );
        }

        $body = $response->json();
        $rawChannels = is_array($body) && is_array($body['data'] ?? null)
            ? $body['data']
            : $body;
        $channels = [];

        foreach (is_array($rawChannels) ? $rawChannels : [] as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $code = strtoupper(trim((string) ($channel['channel_code'] ?? '')));
            $name = trim((string) ($channel['channel_name'] ?? $channel['name'] ?? ''));
            $currency = strtoupper(trim((string) ($channel['currency'] ?? '')));
            $category = strtoupper(str_replace(['-', ' '], '_', trim((string) ($channel['channel_category'] ?? ''))));
            $category = $category === 'E_WALLET' ? 'EWALLET' : $category;

            if (! str_starts_with($code, 'PH_') || $name === '' || $currency !== 'PHP' || ! in_array($category, ['BANK', 'EWALLET'], true)) {
                continue;
            }

            $channels[$code] = [
                'channel_code' => $code,
                'channel_name' => $name,
                'channel_category' => $category,
                'currency' => $currency,
            ];
        }

        $channels = array_values($channels);
        usort($channels, fn (array $left, array $right): int => strcasecmp($left['channel_name'], $right['channel_name']));

        return [
            'countries' => [[
                'code' => 'PH',
                'name' => 'Philippines',
                'currency' => 'PHP',
            ]],
            'banks' => array_values(array_filter($channels, fn (array $channel): bool => $channel['channel_category'] === 'BANK')),
            'e_wallets' => array_values(array_filter($channels, fn (array $channel): bool => $channel['channel_category'] === 'EWALLET')),
        ];
    }

    /** @return array{payout_id: string, status: string, reference_id: string|null} */
    public function createPayout(ShopPaymentIntegration $integration, SupplierPaymentAttempt $attempt): array
    {
        $secretKey = trim((string) $integration->secret_key);
        if (! $integration->isConnected() || $secretKey === '') {
            throw new FinanceDomainException(
                'Xendit supplier payouts are not configured for this shop.',
                'XENDIT_NOT_CONFIGURED',
                422,
            );
        }

        $attempt->loadMissing(['supplier']);
        $supplier = $attempt->supplier;
        if (! $supplier instanceof Supplier) {
            throw new FinanceDomainException('The supplier payment destination is unavailable.', 'INVALID_STATE', 422);
        }

        $payload = $this->payload($attempt);
        $testMode = strtolower(trim((string) $integration->environment)) === 'test';

        try {
            $response = $this->client($secretKey)
                ->withHeaders(['Idempotency-key' => (string) $attempt->idempotency_key])
                ->post($this->endpoint('/v3/payouts'), $payload);
        } catch (Throwable) {
            $this->logFailure('create_payout', (int) $integration->shop_owner_id, (int) $attempt->id, null);

            throw new FinanceDomainException(
                'The supplier payout is processing, but Xendit did not confirm the request. Please wait for the payout update.',
                'XENDIT_PAYOUT_UNKNOWN',
                502,
            );
        }

        if (! $response->successful()) {
            $diagnostics = $this->validationDiagnostics(
                $response,
                $payload,
                $secretKey,
                $testMode,
                (string) $attempt->idempotency_key,
            );
            $this->logFailure('create_payout', (int) $integration->shop_owner_id, (int) $attempt->id, $response, $diagnostics);

            if ($response->serverError() || $response->status() === 429) {
                throw new FinanceDomainException(
                    'The supplier payout is processing, but Xendit did not confirm the request. Please wait for the payout update.',
                    'XENDIT_PAYOUT_UNKNOWN',
                    502,
                );
            }

            throw $this->payoutFailure($response, $diagnostics);
        }

        $payoutId = trim((string) $response->json('payout_id'));
        if ($payoutId === '') {
            $this->logFailure('create_payout_missing_id', (int) $integration->shop_owner_id, (int) $attempt->id, $response);

            throw new FinanceDomainException(
                'The supplier payout is processing, but Xendit returned an incomplete response. Please wait for the payout update.',
                'XENDIT_PAYOUT_UNKNOWN',
                502,
            );
        }

        return [
            'payout_id' => $payoutId,
            'status' => strtoupper(trim((string) $response->json('status', 'ACCEPTED'))),
            'reference_id' => $response->json('reference_id') !== null
                ? (string) $response->json('reference_id')
                : null,
        ];
    }

    public function localStatus(string $providerStatus): string
    {
        return match (strtoupper(trim($providerStatus))) {
            'PENDING_COMPLIANCE_REVIEW' => SupplierPaymentAttempt::STATUS_PENDING_COMPLIANCE,
            'REJECTED' => SupplierPaymentAttempt::STATUS_REJECTED,
            'FAILED', 'CANCELLED', 'EXPIRED' => SupplierPaymentAttempt::STATUS_FAILED,
            default => SupplierPaymentAttempt::STATUS_PROCESSING,
        };
    }

    /** @return array<string, mixed> */
    private function payload(SupplierPaymentAttempt $attempt): array
    {
        $destination = (array) $attempt->destination_snapshot;
        $destinationType = (string) ($destination['destination_type'] ?? '');
        $accountNumber = $destinationType === 'e_wallet'
            ? trim((string) ($destination['account_identifier'] ?? ''))
            : trim((string) ($destination['account_number'] ?? ''));
        $accountName = trim((string) ($destination['account_name'] ?? ''));
        $routingValue = $destinationType === 'e_wallet'
            ? $this->walletRoutingValue((string) ($destination['wallet_provider'] ?? ''))
            : trim((string) ($destination['bank_code'] ?? ''));

        if ($accountNumber === '' || $accountName === '' || $routingValue === '') {
            throw new FinanceDomainException(
                'The supplier payment profile is missing Xendit payout details.',
                'XENDIT_DESTINATION_INVALID',
                422,
            );
        }

        $recipientType = strtolower(trim((string) ($destination['recipient_type'] ?? '')));
        $country = strtoupper(trim((string) ($destination['recipient_country'] ?? '')));
        if ($country !== 'PH') {
            throw new FinanceDomainException(
                'Phase 1 Xendit supplier payouts support Philippine destinations only.',
                'XENDIT_DESTINATION_UNSUPPORTED',
                422,
            );
        }

        $identity = match ($recipientType) {
            'business' => ['business_name' => Str::limit(trim((string) ($destination['business_name'] ?? '')), 50, '')],
            'individual' => [
                'given_name' => Str::limit(trim((string) ($destination['given_name'] ?? '')), 50, ''),
                'surname' => Str::limit(trim((string) ($destination['surname'] ?? '')), 50, ''),
            ],
            default => [],
        };
        if ($identity === [] || collect($identity)->contains('')) {
            throw new FinanceDomainException(
                'The supplier payment profile is missing Xendit recipient details.',
                'XENDIT_DESTINATION_INVALID',
                422,
            );
        }

        $address = [
            'country' => 'PH',
            'province_state' => Str::limit(trim((string) ($destination['recipient_province_state'] ?? '')), 255, ''),
            'city' => Str::limit(trim((string) ($destination['recipient_city'] ?? '')), 255, ''),
            'street_line_1' => Str::limit(trim((string) ($destination['recipient_street_line_1'] ?? '')), 255, ''),
            'postal_code' => Str::limit(trim((string) ($destination['recipient_postal_code'] ?? '')), 32, ''),
        ];
        if (collect($address)->contains(fn (mixed $value): bool => $value === '')) {
            throw new FinanceDomainException(
                'The supplier payment profile is missing Xendit recipient details.',
                'XENDIT_DESTINATION_INVALID',
                422,
            );
        }

        $streetLine2 = Str::limit(trim((string) ($destination['recipient_street_line_2'] ?? '')), 255, '');
        if ($streetLine2 !== '') {
            $address['street_line_2'] = $streetLine2;
        }

        return [
            'reference_id' => (string) $attempt->internal_reference,
            'recipient' => [
                'type' => strtoupper($recipientType),
                ...$identity,
                'relationship' => 'SUPPLIER',
                'address' => $address,
                'account_details' => [
                    'currency' => 'PHP',
                    'account_country' => 'PH',
                    'account_holder_name' => Str::limit($accountName, 255, ''),
                    'account_number' => $accountNumber,
                    'routing_type_1' => $destinationType === 'e_wallet' ? 'WALLET' : 'SWIFT',
                    'routing_value_1' => $routingValue,
                ],
            ],
            'payout_details' => [
                'source_currency' => 'PHP',
                'source_amount' => $this->toCents($attempt->amount),
                'destination_currency' => 'PHP',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'TRADES',
            'description' => Str::limit('Supplier payment '.$attempt->internal_reference, 100, ''),
        ];
    }

    private function client(string $secretKey): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->withHeaders(['Api-version' => (string) config('services.xendit.api_version', '2025-09-01')])
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/').$path;
    }

    private function walletRoutingValue(string $provider): string
    {
        $provider = trim($provider);
        if ($provider === '') {
            return '';
        }

        return match (strtolower(trim($provider))) {
            'gcash' => 'PH_GCASH',
            'maya', 'paymaya' => 'PH_MAYA',
            default => 'PH_'.preg_replace('/[^A-Z0-9]+/i', '_', strtoupper(trim($provider))),
        };
    }

    private function toCents(mixed $amount): int
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            throw new FinanceDomainException('The supplier payment amount is invalid.', 'INVALID_STATE', 422);
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @return array<string, mixed>|null */
    private function validationDiagnostics(
        Response $response,
        array $payload,
        string $secretKey,
        bool $testMode,
        string $idempotencyKey,
    ): ?array {
        $providerCode = $this->providerErrorCode($response);
        if (! $testMode || $providerCode !== 'API_VALIDATION_ERROR') {
            return null;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $redactions = $this->diagnosticRedactions($payload, $secretKey);
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];

        return [
            'expose' => true,
            'http_status' => $response->status(),
            'error_code' => $providerCode,
            'message' => $this->sanitizeProviderValue($body['message'] ?? null, $redactions),
            'errors' => $this->sanitizeProviderValue($errors, $redactions),
            'request_payload' => $this->sanitizeProviderValue($payload, $redactions),
            'request_headers' => [
                'api-version' => (string) config('services.xendit.api_version', '2025-09-01'),
                'idempotency-key_present' => trim($idempotencyKey) !== '',
            ],
        ];
    }

    private function logFailure(
        string $operation,
        ?int $shopId,
        ?int $attemptId,
        ?Response $response,
        ?array $diagnostics = null,
    ): void {
        $context = [
            'operation' => $operation,
            'shop_id' => $shopId,
            'attempt_id' => $attemptId,
            'http_status' => $response?->status(),
            'provider_code' => $response ? $this->providerErrorCode($response) : null,
            'provider_field' => $response ? $this->providerErrorPath($response) : null,
        ];
        if ($diagnostics !== null) {
            $context['xendit_diagnostics'] = $diagnostics;
        }

        Log::warning('Xendit supplier payout request failed.', $context);
    }

    private function payoutFailure(Response $response, ?array $diagnostics = null): FinanceDomainException
    {
        $providerCode = $this->providerErrorCode($response);

        if ($response->status() === 401) {
            return new FinanceDomainException(
                'Xendit rejected the secret key. Check that the Test/Live key is correct and has not been revoked, then start a new payment attempt.',
                'XENDIT_KEY_REJECTED',
                422,
            );
        }

        if ($response->status() === 403 || $providerCode === 'REQUEST_FORBIDDEN_ERROR') {
            return new FinanceDomainException(
                'Xendit rejected this payout because the shop API key does not have Money-Out Write permission. Update the key permissions, then start a new payment attempt.',
                'XENDIT_PERMISSION_DENIED',
                422,
            );
        }

        if ($response->clientError()) {
            $labels = array_filter([$providerCode, ($path = $this->providerErrorPath($response)) ? "field: {$path}" : null]);
            $providerCodeLabel = $labels !== [] ? ' ('.implode(', ', $labels).')' : '';

            return new FinanceDomainException(
                "Xendit rejected the payout details{$providerCodeLabel}. Check the supplier bank code, account number, and payout destination, then start a new payment attempt.",
                'XENDIT_PAYOUT_INVALID',
                422,
                $diagnostics,
            );
        }

        return new FinanceDomainException(
            'Xendit could not accept the supplier payout. No payment was recorded.',
            'XENDIT_PAYOUT_FAILED',
            502,
        );
    }

    /** @return array<string, string> */
    private function diagnosticRedactions(array $payload, string $secretKey): array
    {
        $accountNumber = (string) ($payload['recipient']['account_details']['account_number'] ?? '');
        $redactions = [];
        if ($secretKey !== '') {
            $redactions[$secretKey] = '[REDACTED_SECRET_KEY]';
        }
        if ($accountNumber !== '') {
            $redactions[$accountNumber] = SupplierPaymentProfile::maskAccountNumber($accountNumber);
        }

        foreach ([
            'business_name',
            'given_name',
            'surname',
            'account_holder_name',
            'province_state',
            'city',
            'street_line_1',
            'street_line_2',
            'postal_code',
        ] as $field) {
            $value = match ($field) {
                'account_holder_name' => $payload['recipient']['account_details'][$field] ?? null,
                'province_state', 'city', 'street_line_1', 'street_line_2', 'postal_code' => $payload['recipient']['address'][$field] ?? null,
                default => $payload['recipient'][$field] ?? null,
            };
            if (is_string($value) && strlen(trim($value)) >= 4) {
                $redactions[$value] = '[REDACTED]';
            }
        }

        return $redactions;
    }

    private function sanitizeProviderValue(mixed $value, array $redactions): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $entry) {
                $field = strtolower(str_replace(['-', ' '], '_', (string) $key));
                $sanitized[$key] = $this->isSensitiveField($field)
                    ? $this->maskedSensitiveValue($entry, $field)
                    : $this->sanitizeProviderValue($entry, $redactions);
            }

            return $sanitized;
        }

        if (! is_string($value)) {
            return $value;
        }

        $safe = $value;
        foreach ($redactions as $sensitiveValue => $replacement) {
            $safe = str_replace($sensitiveValue, $replacement, $safe);
        }
        $safe = preg_replace('/\bxnd_[A-Za-z0-9_-]+\b/', '[REDACTED_SECRET_KEY]', $safe) ?? $safe;

        return preg_replace('/(?<!\d)\d{8,}(?!\d)/', '[REDACTED_NUMBER]', $safe) ?? $safe;
    }

    private function isSensitiveField(string $field): bool
    {
        return in_array($field, [
            'account_number',
            'account_identifier',
            'account_holder_name',
            'authorization',
            'api_key',
            'callback_token',
            'business_name',
            'city',
            'password',
            'province_state',
            'secret_key',
            'street_line_1',
            'street_line_2',
            'surname',
            'given_name',
            'postal_code',
            'webhook_token',
        ], true) || str_contains($field, 'token');
    }

    private function maskedSensitiveValue(mixed $value, string $field): string
    {
        if (str_contains($field, 'account')) {
            return SupplierPaymentProfile::maskAccountNumber((string) $value) ?? '';
        }

        return '[REDACTED]';
    }

    private function providerErrorCode(Response $response): ?string
    {
        $code = $response->json('error_code') ?? $response->json('code');
        if (! is_scalar($code)) {
            return null;
        }

        $code = preg_replace('/[^A-Z0-9_.-]/', '_', strtoupper(trim((string) $code)));

        return $code !== '' ? Str::limit($code, 100, '') : null;
    }

    private function providerErrorPath(Response $response): ?string
    {
        $errors = $response->json('errors');
        if (! is_array($errors)) {
            return null;
        }

        foreach ($errors as $error) {
            $path = is_array($error) && is_scalar($error['path'] ?? null)
                ? trim((string) $error['path'])
                : '';
            if (preg_match('/^(?:recipient\.(?:type|business_name|given_name|surname|relationship|address\.(?:country|province_state|city|street_line_1|street_line_2|postal_code)|account_details\.(?:currency|account_country|account_holder_name|account_number|routing_type_1|routing_value_1))|payout_details\.(?:source_currency|source_amount|destination_currency))$/', $path)) {
                return $path;
            }
        }

        return null;
    }

    private function verificationFailureMessage(Response $response): string
    {
        return match ($response->status()) {
            401 => 'Xendit rejected this secret key. Check that the Test/Live key is correct and has not been revoked.',
            403 => 'Xendit rejected this key. Grant Money-Out Read access for balance verification and Money-Out Write access for supplier payouts.',
            default => 'Xendit could not verify this account. Check the key, environment, and account permissions, then try again.',
        };
    }
}
