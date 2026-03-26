<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymongoRefundService
{
    public function createRefund(
        string $secretKey,
        string $paymentId,
        int $amountInCentavos,
        string $reason = 'requested_by_customer'
    ): array {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
                'Accept' => 'application/json',
            ])->post('https://api.paymongo.com/v1/refunds', [
                'data' => [
                    'attributes' => [
                        'payment_id' => $paymentId,
                        'amount' => $amountInCentavos,
                        'reason' => $reason,
                    ],
                ],
            ]);

            if ($response->failed()) {
                $errors = $response->json('errors') ?? [];
                $message = $errors[0]['detail'] ?? $response->json('message') ?? 'PayMongo refund API failed';

                Log::warning('PayMongo refund request failed', [
                    'status' => $response->status(),
                    'payment_id' => $paymentId,
                    'amount' => $amountInCentavos,
                    'message' => $message,
                ]);

                return [
                    'success' => false,
                    'message' => (string) $message,
                    'status' => null,
                    'refund_id' => null,
                    'raw' => $response->json(),
                ];
            }

            $data = $response->json('data') ?? [];
            $attributes = $data['attributes'] ?? [];

            return [
                'success' => true,
                'message' => 'Refund request accepted by PayMongo',
                'status' => strtolower((string) ($attributes['status'] ?? 'processing')),
                'refund_id' => $data['id'] ?? null,
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('PayMongo refund exception', [
                'payment_id' => $paymentId,
                'amount' => $amountInCentavos,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Refund request failed: ' . $e->getMessage(),
                'status' => null,
                'refund_id' => null,
                'raw' => null,
            ];
        }
    }

    public function getRefundStatus(string $secretKey, string $refundId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
                'Accept' => 'application/json',
            ])->get('https://api.paymongo.com/v1/refunds/' . $refundId);

            if ($response->failed()) {
                $errors = $response->json('errors') ?? [];
                $message = $errors[0]['detail'] ?? $response->json('message') ?? 'PayMongo refund status API failed';

                return [
                    'success' => false,
                    'message' => (string) $message,
                    'status' => null,
                    'refund_id' => $refundId,
                    'raw' => $response->json(),
                ];
            }

            $data = $response->json('data') ?? [];
            $attributes = $data['attributes'] ?? [];

            return [
                'success' => true,
                'message' => 'Refund status fetched',
                'status' => strtolower((string) ($attributes['status'] ?? 'processing')),
                'refund_id' => (string) ($data['id'] ?? $refundId),
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::warning('PayMongo refund status exception', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Refund status fetch failed: ' . $e->getMessage(),
                'status' => null,
                'refund_id' => $refundId,
                'raw' => null,
            ];
        }
    }
}
