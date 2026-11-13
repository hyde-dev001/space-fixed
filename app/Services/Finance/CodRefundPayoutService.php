<?php

namespace App\Services\Finance;

use App\Models\CodCollection;
use App\Models\CodRemittance;
use App\Models\CodRemittanceItem;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\ShopPaymentIntegration;
use App\Services\PaymentSettlementService;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class CodRefundPayoutService
{
    public const PROVIDER = 'xendit';
    public const PAYOUT_STATUS_NOT_STARTED = 'not_started';
    public const PAYOUT_STATUS_PROCESSING = 'processing';
    public const PAYOUT_STATUS_SUCCEEDED = 'succeeded';
    public const PAYOUT_STATUS_FAILED = 'failed';
    public const PAYOUT_STATUS_REVERSED = 'reversed';

    public function __construct(
        private readonly XenditClient $xenditClient,
        private readonly PaymentSettlementService $paymentSettlementService,
    ) {}

    public static function resolveAmount(float $amount, ?Order $order): float
    {
        $amount = round(max(0, $amount), 2);
        if (! $order) {
            return $amount;
        }

        $shipping = round(max(0, (float) ($order->shipping_fee ?? 0)), 2);
        $capturedAmount = round(max(
            0,
            (float) ($order->grand_total ?? 0),
            (float) ($order->total ?? 0),
        ), 2);
        $productAmount = $capturedAmount > 0
            ? round(max(0, $capturedAmount - $shipping), 2)
            : round(max(0, (float) ($order->total_amount ?? 0) + (float) ($order->vat_amount ?? 0)), 2);

        return $productAmount > 0 ? min($amount, $productAmount) : $amount;
    }

    /** @param array<string, mixed>|null $destination */
    public function hasValidDestination(?array $destination): bool
    {
        if (! is_array($destination)) {
            return false;
        }

        $type = strtolower(trim((string) ($destination['type'] ?? '')));
        $accountName = trim((string) ($destination['account_name'] ?? $destination['account_holder_name'] ?? ''));
        $accountNumber = trim((string) ($destination['account_number'] ?? $destination['number'] ?? ''));

        if ($accountName === '' || $accountNumber === '') {
            return false;
        }

        return match ($type) {
            'gcash' => true,
            'bank' => trim((string) ($destination['channel'] ?? $destination['bank'] ?? '')) !== '',
            'bank_account', 'e_wallet' => trim((string) ($destination['channel_code'] ?? '')) !== '',
            default => false,
        };
    }

    public function execute(OrderRefund $refund, ?int $processedBy = null, ?string $executionNote = null): array
    {
        $context = DB::transaction(function () use ($refund, $processedBy, $executionNote): array {
            $lockedRefund = OrderRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($lockedRefund->order_id)->lockForUpdate()->firstOrFail();
            $collection = CodCollection::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $collection || (float) ($collection->collected_amount ?? 0) <= 0) {
                throw new FinanceDomainException(
                    'There is no collected COD cash to refund.',
                    'COD_NOT_COLLECTED',
                    422,
                );
            }

            $remittanceItem = CodRemittanceItem::query()
                ->where('cod_collection_id', $collection->id)
                ->lockForUpdate()
                ->first();
            $remittance = $remittanceItem
                ? CodRemittance::query()->whereKey($remittanceItem->cod_remittance_id)->lockForUpdate()->first()
                : null;

            if ((string) $collection->status !== CodCollection::STATUS_SETTLED
                || ! $remittance
                || (string) $remittance->status !== CodRemittance::STATUS_SETTLED) {
                throw new FinanceDomainException(
                    'COD remittance must be settled before the Xendit refund payout can execute.',
                    'COD_REMITTANCE_NOT_SETTLED',
                    422,
                );
            }

            $payoutStatus = (string) ($lockedRefund->payout_status ?? self::PAYOUT_STATUS_NOT_STARTED);
            if ($payoutStatus === self::PAYOUT_STATUS_SUCCEEDED || (string) $lockedRefund->status === 'succeeded') {
                return [
                    'result' => 'already_refunded',
                    'message' => 'COD refund payout has already succeeded.',
                    'refund' => $lockedRefund->fresh(),
                    'integration' => null,
                    'payload' => null,
                ];
            }

            if ($payoutStatus === self::PAYOUT_STATUS_PROCESSING || (string) $lockedRefund->status === 'processing') {
                return [
                    'result' => 'already_processing',
                    'message' => 'COD refund payout is already processing.',
                    'refund' => $lockedRefund->fresh(),
                    'integration' => null,
                    'payload' => null,
                ];
            }

            $amount = self::resolveAmount((float) ($lockedRefund->amount ?? 0), $order);
            if (round((float) ($lockedRefund->amount ?? 0), 2) !== $amount) {
                $lockedRefund->update(['amount' => $amount]);
            }
            $otherReserved = (float) OrderRefund::query()
                ->where('order_id', $order->id)
                ->whereKeyNot($lockedRefund->id)
                ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
                ->lockForUpdate()
                ->sum('amount');
            $remaining = round(max(0, (float) $collection->collected_amount - $otherReserved), 2);
            if ($amount <= 0 || $amount > $remaining) {
                throw new FinanceDomainException(
                    'The COD refund amount exceeds the settled COD balance available for refund.',
                    'COD_REFUND_LIMIT_EXCEEDED',
                    422,
                );
            }

            $destination = is_array($lockedRefund->refund_destination)
                ? $lockedRefund->refund_destination
                : [];
            if (! $this->hasValidDestination($destination)) {
                throw new FinanceDomainException(
                    'Customer must provide a refund destination before the Xendit refund payout can execute.',
                    'COD_REFUND_DESTINATION_REQUIRED',
                    422,
                );
            }

            $integration = ShopPaymentIntegration::query()
                ->forXenditMoneyOut((int) $order->shop_owner_id)
                ->lockForUpdate()
                ->first();
            if (! $integration || ! $integration->isConnected()) {
                throw new FinanceDomainException(
                    'Xendit customer refunds are not configured for this shop.',
                    'XENDIT_NOT_CONFIGURED',
                    422,
                );
            }

            $reference = 'CODR-' . $lockedRefund->id;
            $idempotencyKey = 'cod-refund-payout:' . $lockedRefund->id;

            $lockedRefund->update([
                'status' => 'processing',
                'refund_provider' => self::PROVIDER,
                'payout_status' => self::PAYOUT_STATUS_PROCESSING,
                'payout_idempotency_key' => $idempotencyKey,
                'provider_reference' => $reference,
                'payout_initiated_at' => now(),
                'refund_executed_at' => now(),
                'processed_by' => $processedBy,
                'reason_note' => $executionNote
                    ? trim((string) ($lockedRefund->reason_note ?? '') . "\n\nFinance payout note: " . $executionNote)
                    : $lockedRefund->reason_note,
            ]);

            return [
                'result' => 'started',
                'message' => 'COD refund payout has been submitted to Xendit and is processing.',
                'refund' => $lockedRefund->fresh(),
                'integration' => $integration->fresh(),
                'payload' => $this->payload($reference, $destination, $amount, $order),
            ];
        }, 3);

        if (($context['result'] ?? null) !== 'started') {
            return $context;
        }

        /** @var ShopPaymentIntegration $integration */
        $integration = $context['integration'];
        /** @var array<string, mixed> $payload */
        $payload = $context['payload'];
        $refundId = (int) $context['refund']->id;
        $idempotencyKey = 'cod-refund-payout:' . $refundId;

        try {
            $response = $this->xenditClient
                ->request((string) $integration->secret_key)
                ->withHeaders(['Idempotency-key' => $idempotencyKey])
                ->post($this->xenditClient->endpoint('/v3/payouts'), $payload);
        } catch (Throwable $exception) {
            Log::warning('COD Xendit payout request outcome is unknown.', [
                'refund_id' => $refundId,
                'shop_id' => (int) $integration->shop_owner_id,
                'error_class' => $exception::class,
            ]);

            return [
                'result' => 'processing',
                'message' => 'Xendit did not confirm the COD refund request. The payout remains processing until webhook reconciliation.',
                'refund' => $context['refund']->fresh(),
            ];
        }

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                return [
                    'result' => 'processing',
                    'message' => 'Xendit did not confirm the COD refund request. The payout remains processing until webhook reconciliation.',
                    'refund' => $context['refund']->fresh(),
                ];
            }

            $failureCode = $this->providerCode($response);
            $failureMessage = $this->providerFailureMessage($response);
            Log::warning('COD Xendit payout request failed.', [
                'refund_id' => $refundId,
                'shop_id' => (int) $integration->shop_owner_id,
                'http_status' => $response->status(),
                'provider_code' => $failureCode,
                'provider_field' => $this->providerErrorPath($response),
            ]);
            $failed = $this->markFailed($refundId, $failureCode, $failureMessage);

            return [
                'result' => 'failed',
                'message' => $failureMessage,
                'refund' => $failed,
            ];
        }

        $payoutId = trim((string) $response->json('payout_id'));
        if ($payoutId === '') {
            return [
                'result' => 'processing',
                'message' => 'Xendit accepted the COD refund request without a payout ID. Webhook reconciliation is required.',
                'refund' => $context['refund']->fresh(),
            ];
        }

        $updated = DB::transaction(function () use ($refundId, $payoutId): OrderRefund {
            $lockedRefund = OrderRefund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();
            if ((string) ($lockedRefund->payout_status ?? '') !== self::PAYOUT_STATUS_SUCCEEDED) {
                $lockedRefund->update([
                    'provider_payout_id' => $payoutId,
                    'provider_reference' => $lockedRefund->provider_reference ?: 'CODR-' . $lockedRefund->id,
                ]);
            }

            return $lockedRefund->fresh();
        }, 3);

        activity('refunds')
            ->performedOn($updated)
            ->withProperties([
                'refund_id' => (int) $updated->id,
                'shop_id' => (int) ($updated->shop_owner_id ?? 0),
                'provider' => self::PROVIDER,
                'provider_payout_id' => $payoutId,
            ])
            ->log('cod_refund_payout_initiated');

        return [
            'result' => 'processing',
            'message' => 'COD refund payout has been submitted to Xendit and is processing.',
            'refund' => $updated,
        ];
    }

    public function reconcileWithProvider(OrderRefund $refund): OrderRefund
    {
        $refund->loadMissing('order:id,payment_method,shop_owner_id');

        $paymentMethod = strtolower(trim((string) ($refund->order?->payment_method ?? '')));
        if (! in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true)
            || strtolower(trim((string) ($refund->refund_provider ?? ''))) !== self::PROVIDER
            || (string) ($refund->payout_status ?? '') !== self::PAYOUT_STATUS_PROCESSING) {
            return $refund;
        }

        $payoutId = trim((string) ($refund->provider_payout_id ?? ''));
        if ($payoutId === '') {
            return $refund;
        }

        $integration = ShopPaymentIntegration::query()
            ->forXenditMoneyOut((int) ($refund->shop_owner_id ?? $refund->order?->shop_owner_id ?? 0))
            ->first();
        if (! $integration || ! $integration->isConnected()) {
            return $refund;
        }

        try {
            $response = $this->xenditClient
                ->request((string) $integration->secret_key)
                ->get($this->xenditClient->endpoint('/v3/payouts/' . rawurlencode($payoutId)));
        } catch (Throwable $exception) {
            Log::warning('COD Xendit payout reconciliation failed.', [
                'refund_id' => (int) $refund->id,
                'provider_payout_id' => $payoutId,
                'error_class' => $exception::class,
            ]);

            return $refund->fresh() ?? $refund;
        }

        if (! $response->successful()) {
            Log::warning('COD Xendit payout reconciliation returned an error.', [
                'refund_id' => (int) $refund->id,
                'provider_payout_id' => $payoutId,
                'http_status' => $response->status(),
            ]);

            return $refund->fresh() ?? $refund;
        }

        $providerPayload = $response->json();
        $providerStatus = strtoupper(trim((string) ($providerPayload['status'] ?? data_get($providerPayload, 'data.status', ''))));
        $event = match ($providerStatus) {
            'SUCCEEDED', 'SUCCESS', 'COMPLETED', 'PAID' => 'v3_payout.succeeded',
            'FAILED' => 'v3_payout.failed',
            'REJECTED' => 'v3_payout.rejected',
            'REVERSED' => 'v3_payout.reversed',
            'PENDING', 'PENDING_COMPLIANCE', 'ACCEPTED', 'PROCESSING' => 'v3_payout.pending_compliance',
            default => null,
        };

        if ($event === null) {
            return $refund->fresh() ?? $refund;
        }

        $data = [
            'payout_id' => trim((string) ($providerPayload['payout_id'] ?? data_get($providerPayload, 'data.payout_id', $payoutId))) ?: $payoutId,
            'reference_id' => trim((string) ($providerPayload['reference_id'] ?? data_get($providerPayload, 'data.reference_id', $refund->provider_reference))) ?: $refund->provider_reference,
        ];
        if (isset($providerPayload['failure_code']) || data_get($providerPayload, 'data.failure_code') !== null) {
            $data['failure_code'] = $providerPayload['failure_code'] ?? data_get($providerPayload, 'data.failure_code');
        }

        return $this->handleWebhook($refund->fresh() ?? $refund, [
            'event' => $event,
            'data' => $data,
        ]);
    }

    public function handleWebhook(OrderRefund $refund, array $payload): OrderRefund
    {
        $event = trim((string) ($payload['event'] ?? ''));
        $eventStatus = match ($event) {
            'v3_payout.succeeded' => self::PAYOUT_STATUS_SUCCEEDED,
            'v3_payout.failed', 'v3_payout.rejected' => self::PAYOUT_STATUS_FAILED,
            'v3_payout.pending_compliance' => self::PAYOUT_STATUS_PROCESSING,
            'v3_payout.reversed' => self::PAYOUT_STATUS_REVERSED,
            default => null,
        };
        if ($eventStatus === null) {
            throw new FinanceDomainException('Unsupported Xendit payout event.', 'XENDIT_EVENT_UNSUPPORTED', 422);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $payoutId = trim((string) ($data['payout_id'] ?? ''));
        $referenceId = trim((string) ($data['reference_id'] ?? ''));
        $auditStatus = null;

        $updated = DB::transaction(function () use ($refund, $data, $payoutId, $referenceId, $eventStatus, &$auditStatus): OrderRefund {
            $lockedRefund = OrderRefund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
            $expectedPayoutId = trim((string) ($lockedRefund->provider_payout_id ?? ''));
            $expectedReference = trim((string) ($lockedRefund->provider_reference ?? ''));

            if ($payoutId !== '' && $expectedPayoutId !== '' && $payoutId !== $expectedPayoutId) {
                throw new FinanceDomainException('The Xendit payout does not match the COD refund.', 'XENDIT_EVENT_MISMATCH', 422);
            }
            if ($referenceId !== '' && $expectedReference !== '' && $referenceId !== $expectedReference) {
                throw new FinanceDomainException('The Xendit payout reference does not match the COD refund.', 'XENDIT_EVENT_MISMATCH', 422);
            }

            $this->assertAmount($lockedRefund, $data);

            if ($eventStatus === self::PAYOUT_STATUS_SUCCEEDED) {
                if ($payoutId === '' && $expectedPayoutId === '') {
                    throw new FinanceDomainException('The Xendit payout confirmation is missing its payout ID.', 'XENDIT_EVENT_MISMATCH', 422);
                }

                $order = Order::query()->whereKey($lockedRefund->order_id)->lockForUpdate()->firstOrFail();
                if ((string) ($lockedRefund->payout_status ?? '') !== self::PAYOUT_STATUS_SUCCEEDED) {
                    $lockedRefund->update([
                        'status' => 'succeeded',
                        'payout_status' => self::PAYOUT_STATUS_SUCCEEDED,
                        'provider_payout_id' => $payoutId ?: $expectedPayoutId,
                        'provider_reference' => $referenceId ?: $expectedReference,
                        'payout_succeeded_at' => now(),
                        'refunded_at' => now(),
                        'failure_reason' => null,
                        'payout_failure_code' => null,
                        'payout_failure_message' => null,
                    ]);
                    $this->paymentSettlementService->settleOrderRefunded(
                        order: $order,
                        refundId: null,
                        reason: 'cod_refund',
                        note: 'COD refund paid through Xendit.',
                    );
                    $auditStatus = self::PAYOUT_STATUS_SUCCEEDED;
                }
            } elseif ($eventStatus === self::PAYOUT_STATUS_REVERSED) {
                if ((string) ($lockedRefund->payout_status ?? '') !== self::PAYOUT_STATUS_REVERSED) {
                    $lockedRefund->update([
                        'status' => 'failed',
                        'payout_status' => self::PAYOUT_STATUS_REVERSED,
                        'provider_payout_id' => $payoutId ?: $expectedPayoutId,
                        'provider_reference' => $referenceId ?: $expectedReference,
                        'payout_reversed_at' => now(),
                        'payout_failure_code' => 'XENDIT_PAYOUT_REVERSED',
                        'payout_failure_message' => 'Xendit reversed the COD refund payout.',
                    ]);
                    $auditStatus = self::PAYOUT_STATUS_REVERSED;
                }
            } elseif (! in_array((string) ($lockedRefund->payout_status ?? ''), [
                self::PAYOUT_STATUS_SUCCEEDED,
                self::PAYOUT_STATUS_REVERSED,
            ], true)) {
                $updates = [
                    'payout_status' => $eventStatus,
                    'provider_payout_id' => $payoutId ?: $expectedPayoutId,
                    'provider_reference' => $referenceId ?: $expectedReference,
                ];
                if ($eventStatus === self::PAYOUT_STATUS_FAILED) {
                    $updates += [
                        'status' => 'failed',
                        'payout_failed_at' => now(),
                        'payout_failure_code' => $this->safeCode($data['failure_code'] ?? null, 'XENDIT_PAYOUT_FAILED'),
                        'payout_failure_message' => 'Xendit did not complete the COD refund payout.',
                    ];
                } else {
                    $updates['status'] = 'processing';
                }
                $lockedRefund->update($updates);
                $auditStatus = $eventStatus;
            }

            return $lockedRefund->fresh();
        }, 3);

        if ($auditStatus !== null) {
            activity('refunds')
                ->performedOn($updated)
                ->withProperties([
                    'refund_id' => (int) $updated->id,
                    'shop_id' => (int) ($updated->shop_owner_id ?? 0),
                    'provider' => self::PROVIDER,
                    'status' => $auditStatus,
                ])
                ->log('cod_refund_payout_' . $auditStatus);
        }

        return $updated;
    }

    /** @param array<string, mixed> $destination */
    private function payload(string $reference, array $destination, float $amount, Order $order): array
    {
        $accountName = trim((string) ($destination['account_name'] ?? $destination['account_holder_name'] ?? 'Customer'));
        $nameParts = preg_split('/\s+/', $accountName, 2) ?: ['Customer'];
        $givenName = Str::limit(trim((string) ($nameParts[0] ?? 'Customer')) ?: 'Customer', 50, '');
        $surname = Str::limit(trim((string) ($nameParts[1] ?? 'Customer')) ?: 'Customer', 50, '');
        $type = strtolower(trim((string) ($destination['type'] ?? '')));
        $accountNumber = trim((string) ($destination['number'] ?? $destination['account_number'] ?? ''));
        $channel = trim((string) ($destination['channel'] ?? $destination['bank'] ?? ''));
        $channelCode = strtoupper(trim((string) ($destination['channel_code'] ?? '')));
        $isWallet = in_array($type, ['gcash', 'e_wallet'], true);
        $routingValue = $channelCode !== ''
            ? $channelCode
            : ($type === 'gcash'
                ? 'PH_GCASH'
                : strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '_', $channel)));
        $addressLine = trim((string) ($order->shipping_address_line ?? ''));
        $barangay = trim((string) ($order->shipping_barangay ?? ''));
        if ($addressLine === '') {
            $addressLine = trim((string) ($order->customer_address ?? ''));
        } elseif ($barangay !== '') {
            $addressLine .= ', ' . $barangay;
        }
        $recipientAddress = array_filter([
            'country' => 'PH',
            'street_line_1' => Str::limit($addressLine, 255, ''),
            'city' => trim((string) ($order->shipping_city ?? '')),
            'province_state' => trim((string) ($order->shipping_province ?: ($order->shipping_region ?? ''))),
            'postal_code' => trim((string) ($order->shipping_postal_code ?? '')),
        ], static fn (mixed $value): bool => $value !== '');
        $mobileNumber = trim((string) preg_replace('/[^0-9+]/', '', $accountNumber));
        $recipientDetails = $isWallet && $mobileNumber !== ''
            ? ['personal_mobile_number' => Str::limit($mobileNumber, 15, '')]
            : [];

        $recipient = [
            'type' => 'INDIVIDUAL',
            'given_name' => $givenName,
            'surname' => $surname,
            'relationship' => 'CUSTOMER',
            'address' => $recipientAddress,
            'account_details' => [
                'currency' => 'PHP',
                'account_country' => 'PH',
                'account_holder_name' => Str::limit($accountName, 255, ''),
                'account_number' => $accountNumber,
                'routing_type_1' => $isWallet ? 'WALLET' : 'SWIFT',
                'routing_value_1' => $routingValue,
            ],
        ];
        if ($recipientDetails !== []) {
            $recipient['details'] = $recipientDetails;
        }

        return [
            'reference_id' => $reference,
            'recipient' => $recipient,
            'payout_details' => [
                'source_currency' => 'PHP',
                'source_amount' => $this->toCents($amount),
                'destination_currency' => 'PHP',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'REFUND',
            'description' => Str::limit('Customer COD refund ' . $reference, 100, ''),
        ];
    }

    private function markFailed(int $refundId, string $code, string $message): ?OrderRefund
    {
        return DB::transaction(function () use ($refundId, $code, $message): ?OrderRefund {
            $refund = OrderRefund::query()->whereKey($refundId)->lockForUpdate()->first();
            if (! $refund || (string) ($refund->payout_status ?? '') === self::PAYOUT_STATUS_SUCCEEDED) {
                return $refund?->fresh();
            }

            $refund->update([
                'status' => 'failed',
                'payout_status' => self::PAYOUT_STATUS_FAILED,
                'payout_failed_at' => now(),
                'payout_failure_code' => $code,
                'payout_failure_message' => $message,
            ]);

            return $refund->fresh();
        }, 3);
    }

    private function assertAmount(OrderRefund $refund, array $data): void
    {
        $sourceAmount = $data['source_amount'] ?? null;
        if ($sourceAmount !== null && (int) $sourceAmount !== $this->toCents((float) $refund->amount)) {
            throw new FinanceDomainException('The Xendit payout amount does not match the COD refund.', 'XENDIT_EVENT_MISMATCH', 422);
        }

        foreach (['source_currency', 'destination_currency'] as $key) {
            if (isset($data[$key]) && strtoupper(trim((string) $data[$key])) !== 'PHP') {
                throw new FinanceDomainException('The Xendit payout currency does not match the COD refund.', 'XENDIT_EVENT_MISMATCH', 422);
            }
        }
    }

    private function providerCode(Response $response): string
    {
        $code = $response->json('error_code')
            ?? $response->json('code')
            ?? $response->json('failure_code')
            ?? 'XENDIT_PAYOUT_REJECTED';

        return Str::limit((string) (preg_replace('/[^A-Z0-9_.-]/', '_', strtoupper(trim((string) $code))) ?: 'XENDIT_PAYOUT_REJECTED'), 64, '');
    }

    private function providerFailureMessage(Response $response): string
    {
        if ($response->status() === 401) {
            return 'Xendit rejected the secret key. Check that the shop is using the correct Test/Live key with Money-Out access, then retry the payout.';
        }

        $providerCode = $this->providerCode($response);
        if ($response->status() === 403 || $providerCode === 'REQUEST_FORBIDDEN_ERROR') {
            return 'Xendit rejected this payout because the shop API key does not have Money-Out Write permission. Update the key permissions, then retry the payout.';
        }

        if ($response->clientError()) {
            return 'Xendit rejected the COD payout details. Verify the selected channel and account number, then retry the payout.';
        }

        return 'Xendit could not accept the COD refund payout. No payment was recorded; retry after checking the shop Xendit configuration.';
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
            if (preg_match('/^(?:recipient\.(?:type|given_name|surname|relationship|address\.country|account_details\.(?:currency|account_country|account_holder_name|account_number|routing_type_1|routing_value_1))|payout_details\.(?:source_currency|source_amount|destination_currency))$/', $path)) {
                return $path;
            }
        }

        return null;
    }

    private function safeCode(mixed $code, string $fallback): string
    {
        $safe = preg_replace('/[^A-Z0-9_.-]/', '_', strtoupper(trim((string) $code)));

        return Str::limit($safe ?: $fallback, 64, '');
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
