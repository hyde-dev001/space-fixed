<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PaymongoSubscriptionRefundGateway
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    /** @return array{success: bool, payment_id?: string, status?: string, amount?: int, currency?: string, failure_code?: string} */
    public function retrievePayment(string $paymentId): array
    {
        try {
            $response = $this->client()->get(self::BASE_URL.'/payments/'.rawurlencode($paymentId));

            if ($response->failed()) {
                Log::warning('PayMongo subscription payment lookup failed', [
                    'payment_id' => $paymentId,
                    'http_status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'failure_code' => 'provider_payment_lookup_failed',
                ];
            }

            $data = $response->json('data') ?? [];
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $amount = $attributes['amount'] ?? null;
            $currency = strtoupper((string) ($attributes['currency'] ?? ''));
            $providerPaymentId = (string) ($data['id'] ?? '');

            if ($providerPaymentId !== $paymentId || ! is_numeric($amount) || $currency === '') {
                return [
                    'success' => false,
                    'failure_code' => 'provider_payment_mismatch',
                ];
            }

            return [
                'success' => true,
                'payment_id' => $providerPaymentId,
                'status' => strtolower((string) ($attributes['status'] ?? '')),
                'amount' => (int) $amount,
                'currency' => $currency,
            ];
        } catch (ConnectionException) {
            return [
                'success' => false,
                'failure_code' => 'provider_timeout',
            ];
        } catch (\Throwable $exception) {
            Log::warning('PayMongo subscription payment lookup exception', [
                'payment_id' => $paymentId,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'failure_code' => 'provider_payment_lookup_failed',
            ];
        }
    }

    /** @return array{success: bool, refunds: list<array{id: string, payment_id: ?string, amount: ?int, currency: ?string, status: string}>, failure_code?: string} */
    public function listRefunds(string $paymentId): array
    {
        try {
            $response = $this->client()->get(self::BASE_URL.'/refunds', [
                'data' => [
                    'attributes' => [
                        'payment_id' => $paymentId,
                        'limit' => 100,
                    ],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('PayMongo subscription refunds lookup failed', [
                    'payment_id' => $paymentId,
                    'http_status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'refunds' => [],
                    'failure_code' => 'provider_refund_lookup_failed',
                ];
            }

            $rows = $response->json('data') ?? [];
            if (! is_array($rows)) {
                return [
                    'success' => false,
                    'refunds' => [],
                    'failure_code' => 'provider_refund_response_invalid',
                ];
            }

            $refunds = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
                $refundId = (string) ($row['id'] ?? '');
                if ($refundId === '') {
                    continue;
                }

                $refunds[] = [
                    'id' => $refundId,
                    'payment_id' => isset($attributes['payment_id']) ? (string) $attributes['payment_id'] : null,
                    'amount' => is_numeric($attributes['amount'] ?? null) ? (int) $attributes['amount'] : null,
                    'currency' => isset($attributes['currency']) ? strtoupper((string) $attributes['currency']) : null,
                    'status' => strtolower((string) ($attributes['status'] ?? '')),
                ];
            }

            return [
                'success' => true,
                'refunds' => $refunds,
            ];
        } catch (ConnectionException) {
            return [
                'success' => false,
                'refunds' => [],
                'failure_code' => 'provider_timeout',
            ];
        } catch (\Throwable $exception) {
            Log::warning('PayMongo subscription refunds lookup exception', [
                'payment_id' => $paymentId,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'refunds' => [],
                'failure_code' => 'provider_refund_lookup_failed',
            ];
        }
    }

    /** @return array{success: bool, refund?: array{id: string, payment_id: ?string, amount: ?int, currency: ?string, status: string}, failure_code?: string} */
    public function retrieveRefund(string $refundId): array
    {
        try {
            $response = $this->client()->get(self::BASE_URL.'/refunds/'.rawurlencode($refundId));

            if ($response->failed()) {
                return [
                    'success' => false,
                    'failure_code' => 'provider_refund_lookup_failed',
                ];
            }

            $data = $response->json('data') ?? [];
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            if ((string) ($data['id'] ?? '') !== $refundId) {
                return [
                    'success' => false,
                    'failure_code' => 'provider_refund_mismatch',
                ];
            }

            return [
                'success' => true,
                'refund' => [
                    'id' => $refundId,
                    'payment_id' => isset($attributes['payment_id']) ? (string) $attributes['payment_id'] : null,
                    'amount' => is_numeric($attributes['amount'] ?? null) ? (int) $attributes['amount'] : null,
                    'currency' => isset($attributes['currency']) ? strtoupper((string) $attributes['currency']) : null,
                    'status' => strtolower((string) ($attributes['status'] ?? '')),
                ],
            ];
        } catch (ConnectionException) {
            return [
                'success' => false,
                'failure_code' => 'provider_timeout',
            ];
        } catch (\Throwable $exception) {
            Log::warning('PayMongo subscription refund lookup exception', [
                'refund_id' => $refundId,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'failure_code' => 'provider_refund_lookup_failed',
            ];
        }
    }

    /** @return array{outcome: string, refund_id: ?string, amount: ?int, currency: ?string, payment_id: ?string, failure_code: ?string} */
    public function createRefund(
        string $paymentId,
        int $amountInCentavos,
        string $providerReason,
        string $localReference,
    ): array {
        try {
            $response = $this->client()
                ->withHeaders(['Idempotency-Key' => $localReference])
                ->post(self::BASE_URL.'/refunds', [
                    'data' => [
                        'attributes' => [
                            'amount' => $amountInCentavos,
                            'payment_id' => $paymentId,
                            'reason' => $providerReason,
                            'notes' => 'SoleSpace subscription refund '.$localReference,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                $providerCode = (string) ($response->json('errors.0.code') ?? '');

                return [
                    'outcome' => 'failed',
                    'refund_id' => null,
                    'amount' => null,
                    'currency' => null,
                    'payment_id' => null,
                    'failure_code' => $this->safeFailureCode($providerCode),
                ];
            }

            $data = $response->json('data') ?? [];
            $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
            $status = strtolower((string) ($attributes['status'] ?? ''));
            $outcome = match ($status) {
                'succeeded' => 'succeeded',
                'pending', 'processing' => 'processing',
                'failed' => 'failed',
                default => 'unknown',
            };

            return [
                'outcome' => $outcome,
                'refund_id' => isset($data['id']) ? (string) $data['id'] : null,
                'amount' => is_numeric($attributes['amount'] ?? null) ? (int) $attributes['amount'] : null,
                'currency' => isset($attributes['currency']) ? strtoupper((string) $attributes['currency']) : null,
                'payment_id' => isset($attributes['payment_id']) ? (string) $attributes['payment_id'] : null,
                'failure_code' => $outcome === 'failed' ? 'provider_refund_failed' : null,
            ];
        } catch (ConnectionException) {
            return [
                'outcome' => 'unknown',
                'refund_id' => null,
                'amount' => null,
                'currency' => null,
                'payment_id' => null,
                'failure_code' => 'provider_timeout',
            ];
        } catch (\Throwable $exception) {
            Log::warning('PayMongo subscription refund exception', [
                'payment_id' => $paymentId,
                'exception_class' => $exception::class,
            ]);

            return [
                'outcome' => 'unknown',
                'refund_id' => null,
                'amount' => null,
                'currency' => null,
                'payment_id' => null,
                'failure_code' => 'provider_unknown',
            ];
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $secretKey = (string) config('services.paymongo.secret_key');

        return Http::withHeaders([
            'Authorization' => 'Basic '.base64_encode($secretKey.':'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(10)->connectTimeout(3);
    }

    private function safeFailureCode(string $providerCode): string
    {
        return in_array($providerCode, [
            'payment_not_found',
            'payment_not_refundable',
            'refund_amount_exceeds_payment',
        ], true) ? $providerCode : 'provider_rejected';
    }
}
