<?php

namespace App\Services\Finance;

use App\Models\ShopPaymentIntegration;
use App\Models\Supplier;
use App\Models\SupplierPaymentAttempt;
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

        $attempt->loadMissing([
            'supplier',
            'expense.procurementReceipt.purchaseOrder',
        ]);
        $supplier = $attempt->supplier;
        if (! $supplier instanceof Supplier) {
            throw new FinanceDomainException('The supplier payment destination is unavailable.', 'INVALID_STATE', 422);
        }

        $payload = $this->payload($attempt, $supplier);

        try {
            $response = $this->client($secretKey)
                ->withHeaders(['idempotency-key' => (string) $attempt->idempotency_key])
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
            $this->logFailure('create_payout', (int) $integration->shop_owner_id, (int) $attempt->id, $response);

            if ($response->serverError() || $response->status() === 429) {
                throw new FinanceDomainException(
                    'The supplier payout is processing, but Xendit did not confirm the request. Please wait for the payout update.',
                    'XENDIT_PAYOUT_UNKNOWN',
                    502,
                );
            }

            throw $this->payoutFailure($response);
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
    private function payload(SupplierPaymentAttempt $attempt, Supplier $supplier): array
    {
        $destination = (array) $attempt->destination_snapshot;
        $destinationType = (string) ($destination['destination_type'] ?? '');
        $accountNumber = $destinationType === 'e_wallet'
            ? trim((string) ($destination['account_identifier'] ?? ''))
            : trim((string) ($destination['account_number'] ?? ''));
        $accountName = trim((string) ($destination['account_name'] ?? '')) ?: trim((string) $supplier->name);
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

        $country = strtoupper(trim((string) ($supplier->country ?: 'PH')));
        $country = $country === 'PHILIPPINES' ? 'PH' : $country;
        if ($country !== 'PH') {
            throw new FinanceDomainException(
                'Phase 1 Xendit supplier payouts support Philippine destinations only.',
                'XENDIT_DESTINATION_UNSUPPORTED',
                422,
            );
        }

        $purchaseOrder = $attempt->expense?->procurementReceipt?->purchaseOrder;
        $street = trim((string) ($supplier->address ?? ''));
        $city = trim((string) ($supplier->city ?: ''));

        return [
            'reference_id' => (string) $attempt->internal_reference,
            'recipient' => [
                'type' => 'BUSINESS',
                'business_name' => Str::limit($accountName, 50, ''),
                'relationship' => 'SUPPLIER',
                'address' => [
                    'country' => 'PH',
                    'street_line_1' => Str::limit($street !== '' ? $street : $accountName, 255, ''),
                    'city' => Str::limit($city !== '' ? $city : 'Philippines', 255, ''),
                ],
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
            'description' => Str::limit('Supplier payment ' . $attempt->internal_reference, 100, ''),
            'underlying_documents' => $purchaseOrder ? [[
                'type' => 'PURCHASE_ORDER',
                'reference_no' => Str::limit((string) $purchaseOrder->po_number, 100, ''),
            ]] : [],
        ];
    }

    private function client(string $secretKey): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($secretKey, '')
            ->acceptJson()
            ->withHeaders(['api-version' => (string) config('services.xendit.api_version', '2025-09-01')])
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/') . $path;
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
            default => 'PH_' . preg_replace('/[^A-Z0-9]+/i', '_', strtoupper(trim($provider))),
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

    private function logFailure(string $operation, ?int $shopId, ?int $attemptId, ?Response $response): void
    {
        Log::warning('Xendit supplier payout request failed.', [
            'operation' => $operation,
            'shop_id' => $shopId,
            'attempt_id' => $attemptId,
            'http_status' => $response?->status(),
            'provider_code' => $response ? $this->providerErrorCode($response) : null,
        ]);
    }

    private function payoutFailure(Response $response): FinanceDomainException
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
            $providerCodeLabel = $providerCode ? " ({$providerCode})" : '';

            return new FinanceDomainException(
                "Xendit rejected the payout details{$providerCodeLabel}. Check the supplier bank code, account number, and payout destination, then start a new payment attempt.",
                'XENDIT_PAYOUT_INVALID',
                422,
            );
        }

        return new FinanceDomainException(
            'Xendit could not accept the supplier payout. No payment was recorded.',
            'XENDIT_PAYOUT_FAILED',
            502,
        );
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

    private function verificationFailureMessage(Response $response): string
    {
        return match ($response->status()) {
            401 => 'Xendit rejected this secret key. Check that the Test/Live key is correct and has not been revoked.',
            403 => 'Xendit rejected this key. Grant Money-Out Read access for balance verification and Money-Out Write access for supplier payouts.',
            default => 'Xendit could not verify this account. Check the key, environment, and account permissions, then try again.',
        };
    }
}
