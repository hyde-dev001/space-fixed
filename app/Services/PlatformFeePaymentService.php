<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PlatformCreditApplication;
use App\Models\PlatformFeeAdjustment;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentAllocation;
use App\Models\PlatformFeePaymentRequest;
use App\Models\Notification;
use App\Models\ShopOwner;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class PlatformFeePaymentService
{
    public function __construct(
        private readonly PlatformBalanceService $balance,
        private readonly PlatformFeeSettingsResolver $settings,
        private readonly NotificationService $notifications,
        private readonly PlatformFeeThresholdService $thresholds,
    ) {}

    public function createBusinessRequest(int $shopId, ?int $financeUserId = null, ?string $idempotencyKey = null): PlatformFeePaymentRequest
    {
        $request = DB::transaction(function () use ($shopId, $financeUserId, $idempotencyKey): PlatformFeePaymentRequest {
            $shop = ShopOwner::query()->lockForUpdate()->findOrFail($shopId);
            $this->assertBusiness($shop);
            $this->assertTermsAccepted($shop);

            if ($idempotencyKey !== null) {
                $existing = PlatformFeePaymentRequest::query()
                    ->where('shop_id', $shopId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $active = PlatformFeePaymentRequest::query()
                ->where('shop_id', $shopId)
                ->whereIn('status', ['pending_owner_approval', 'owner_approved', 'payment_pending'])
                ->lockForUpdate()
                ->first();
            if ($active) {
                throw new \RuntimeException('An active Platform Balance payment request already exists.');
            }

            $snapshot = $this->positiveSnapshot($shopId);
            return PlatformFeePaymentRequest::query()->create([
                'shop_id' => $shopId,
                'requested_by_user_id' => $financeUserId,
                'requested_amount' => $snapshot['net_payable'],
                'balance_snapshot' => $snapshot['outstanding_balance'],
                'credit_snapshot' => $snapshot['available_credits'],
                'net_payable_snapshot' => $snapshot['net_payable'],
                'status' => 'pending_owner_approval',
                'idempotency_key' => $idempotencyKey ?: 'platform-payment-request:'.str()->uuid(),
                'metadata' => [
                    'shop_type' => $snapshot['shop_type'],
                    'terms_version' => config('platform_fee.terms_version'),
                ],
            ]);
        });

        if ($request->wasRecentlyCreated) {
            $this->audit('platform_payment_requested', $request, [
                'shop_id' => $request->shop_id,
                'requested_amount' => $request->requested_amount,
                'finance_user_id' => $request->requested_by_user_id,
            ]);
            $this->notifyOwner(
                $request,
                NotificationType::PLATFORM_BALANCE_ALERT,
                'Platform Balance Payment Approval Required',
                'Finance submitted a request to pay the full current Platform Balance.',
                '/shop-owner/platform-balance',
                true,
            );
        }

        return $request->fresh();
    }

    public function approve(PlatformFeePaymentRequest $request, ShopOwner $owner): PlatformFeePaymentRequest
    {
        try {
            $updated = DB::transaction(function () use ($request, $owner): PlatformFeePaymentRequest {
                $locked = PlatformFeePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ((int) $locked->shop_id !== (int) $owner->id) {
                    throw new \Illuminate\Auth\Access\AuthorizationException();
                }
                if ($locked->status !== 'pending_owner_approval') {
                    throw new \RuntimeException('This payment request is no longer awaiting owner approval.');
                }
                $this->assertSnapshotCurrent($locked);

                $locked->update([
                    'status' => 'owner_approved',
                    'owner_approved_by_shop_owner_id' => $owner->id,
                    'owner_approved_at' => now(),
                    'owner_rejected_at' => null,
                    'owner_decision_note' => null,
                    'stale_reason' => null,
                    'stale_at' => null,
                ]);

                return $locked->fresh();
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'PAYMENT_REQUEST_STALE') {
                $this->markStale((int) $request->id);
            }
            throw $exception;
        }

        $this->notifyFinance(
            $updated,
            'Platform Balance Payment Approved',
            'The Shop Owner approved the full Platform Balance payment. You may now execute it.',
        );
        $this->audit('platform_payment_request_approved', $updated, [
            'shop_id' => $updated->shop_id,
            'owner_id' => $updated->owner_approved_by_shop_owner_id,
            'approved_amount' => $updated->net_payable_snapshot,
        ]);

        return $updated;
    }

    public function reject(PlatformFeePaymentRequest $request, ShopOwner $owner, ?string $reason = null): PlatformFeePaymentRequest
    {
        $updated = DB::transaction(function () use ($request, $owner, $reason): PlatformFeePaymentRequest {
            $locked = PlatformFeePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ((int) $locked->shop_id !== (int) $owner->id) {
                throw new \Illuminate\Auth\Access\AuthorizationException();
            }
            if ($locked->status !== 'pending_owner_approval') {
                throw new \RuntimeException('This payment request is no longer awaiting owner approval.');
            }

            $locked->update([
                'status' => 'owner_rejected',
                'owner_approved_by_shop_owner_id' => $owner->id,
                'owner_rejected_at' => now(),
                'owner_decision_note' => $reason !== null ? trim($reason) : null,
            ]);

            return $locked->fresh();
        });

        $this->notifyFinance(
            $updated,
            'Platform Balance Payment Rejected',
            'The Shop Owner rejected the Platform Balance payment request.',
        );
        $this->audit('platform_payment_request_rejected', $updated, [
            'shop_id' => $updated->shop_id,
            'owner_id' => $updated->owner_approved_by_shop_owner_id,
            'reason' => $updated->owner_decision_note,
        ]);

        return $updated;
    }

    /** @return array{payment: PlatformFeePayment, checkout_url: string|null} */
    public function executeBusinessRequest(PlatformFeePaymentRequest $request, int $financeUserId): array
    {
        try {
            $payment = DB::transaction(function () use ($request, $financeUserId): PlatformFeePayment {
                $lockedRequest = PlatformFeePaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
                $shop = ShopOwner::query()->lockForUpdate()->findOrFail($lockedRequest->shop_id);
                $this->assertBusiness($shop);

                if ($lockedRequest->status === 'payment_pending' && $lockedRequest->payment) {
                    $existingPayment = $lockedRequest->payment;
                    if ($existingPayment->status === 'failed' && $existingPayment->provider_checkout_id === null) {
                        $metadata = is_array($existingPayment->metadata) ? $existingPayment->metadata : [];
                        unset($metadata['error']);
                        $existingPayment->update([
                            'status' => 'pending',
                            'metadata' => $metadata,
                        ]);

                        return $existingPayment->fresh();
                    }

                    return $existingPayment;
                }
                if ($lockedRequest->status !== 'owner_approved') {
                    throw new \RuntimeException('Only owner-approved payment requests can be executed.');
                }
                $this->assertSnapshotCurrent($lockedRequest);

                $payment = PlatformFeePayment::query()->create([
                    'shop_id' => $shop->id,
                    'amount' => $lockedRequest->net_payable_snapshot,
                    'balance_snapshot' => $lockedRequest->balance_snapshot,
                    'credit_snapshot' => $lockedRequest->credit_snapshot,
                    'net_payable_snapshot' => $lockedRequest->net_payable_snapshot,
                    'status' => 'pending',
                    'idempotency_key' => 'platform-payment:request:'.$lockedRequest->id.':'.str()->uuid(),
                    'metadata' => [
                        'type' => 'platform_fee_payment',
                        'payment_request_id' => (string) $lockedRequest->id,
                        'shop_id' => (string) $shop->id,
                        'currency' => 'PHP',
                    ],
                ]);

                $lockedRequest->update([
                    'status' => 'payment_pending',
                    'executed_by_user_id' => $financeUserId,
                    'platform_fee_payment_id' => $payment->id,
                ]);

                return $payment;
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'PAYMENT_REQUEST_STALE') {
                $this->markStale((int) $request->id);
            }
            throw $exception;
        }

        if ($payment->provider_checkout_id) {
            return [
                'payment' => $payment,
                'checkout_url' => data_get($payment->metadata, 'checkout_url'),
            ];
        }

        return $this->createCheckout($payment, 'Platform Balance payment');
    }

    /** @return array{payment: PlatformFeePayment, checkout_url: string|null} */
    public function createIndividualPayment(ShopOwner $shop): array
    {
        $payment = DB::transaction(function () use ($shop): PlatformFeePayment {
            $lockedShop = ShopOwner::query()->lockForUpdate()->findOrFail($shop->id);
            $this->assertIndividual($lockedShop);
            $this->assertTermsAccepted($lockedShop);

            $existing = PlatformFeePayment::query()
                ->where('shop_id', $lockedShop->id)
                ->where('status', 'pending')
                ->whereNotNull('provider_checkout_id')
                ->latest('id')
                ->first();
            if ($existing) {
                return $existing;
            }

            $snapshot = $this->positiveSnapshot($lockedShop->id);

            return PlatformFeePayment::query()->create([
                'shop_id' => $lockedShop->id,
                'amount' => $snapshot['net_payable'],
                'balance_snapshot' => $snapshot['outstanding_balance'],
                'credit_snapshot' => $snapshot['available_credits'],
                'net_payable_snapshot' => $snapshot['net_payable'],
                'status' => 'pending',
                'idempotency_key' => 'platform-payment:individual:'.$lockedShop->id.':'.str()->uuid(),
                'metadata' => [
                    'type' => 'platform_fee_payment',
                    'shop_id' => (string) $lockedShop->id,
                    'currency' => 'PHP',
                ],
            ]);
        });

        if ($payment->provider_checkout_id) {
            return [
                'payment' => $payment,
                'checkout_url' => data_get($payment->metadata, 'checkout_url'),
            ];
        }

        return $this->createCheckout($payment, 'Platform Balance payment');
    }

    public function settleFromWebhook(string $checkoutId, ?string $providerPaymentId, ?string $currency, ?string $amount): ?PlatformFeePayment
    {
        $payment = PlatformFeePayment::query()->where('provider_checkout_id', $checkoutId)->first();
        if (! $payment) {
            return null;
        }

        $settlement = DB::transaction(function () use ($payment, $providerPaymentId, $currency, $amount): array {
            $locked = PlatformFeePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'paid') {
                return [$locked, false, []];
            }
            if ($locked->status !== 'pending') {
                return [$locked, false, []];
            }

            if (! is_string($providerPaymentId) || trim($providerPaymentId) === '') {
                throw new \RuntimeException('Platform payment verification failed.');
            }

            $expected = $this->decimal((string) $locked->amount);
            $received = $this->decimal((string) ($amount ?? '0'));
            if ($received->compareTo($expected) !== 0 || strtoupper((string) $currency) !== 'PHP') {
                Log::warning('Platform payment webhook amount or currency mismatch', [
                    'payment_id' => $locked->id,
                    'checkout_id' => $checkoutId,
                ]);
                throw new \RuntimeException('Platform payment verification failed.');
            }

            $creditMovements = $this->applyAvailableCreditsLocked((int) $locked->shop_id);
            $this->allocatePaymentLocked($locked);

            $locked->update([
                'provider_payment_id' => $providerPaymentId,
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $request = PlatformFeePaymentRequest::query()
                ->where('platform_fee_payment_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($request) {
                $request->update(['status' => 'paid']);
            }

            return [$locked->fresh(), true, $creditMovements];
        });

        [$settled, $confirmedNow, $creditMovements] = $settlement;
        if (! $confirmedNow) {
            return $settled;
        }

        $this->notifyCreditApplications((int) $settled->shop_id, $creditMovements);
        $this->notifyPaymentReceived($settled);
        $this->audit('platform_payment_confirmed', $settled, [
            'shop_id' => $settled->shop_id,
            'provider_payment_id' => $settled->provider_payment_id,
            'amount' => $settled->amount,
        ]);
        $this->thresholds->evaluate((int) $settled->shop_id);

        return $settled;
    }

    public function reconcileFromProvider(PlatformFeePayment $payment): PlatformFeePayment
    {
        $checkoutId = trim((string) $payment->provider_checkout_id);
        $apiKey = trim((string) config('services.paymongo.secret_key'));
        if ($checkoutId === '' || $apiKey === '') {
            throw new \RuntimeException('Payment gateway verification is not configured.');
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic '.base64_encode($apiKey.':'),
                ])
                ->get('https://api.paymongo.com/v1/checkout_sessions/'.rawurlencode($checkoutId));
        } catch (\Throwable $exception) {
            Log::warning('Platform PayMongo reconciliation request failed', [
                'payment_id' => $payment->id,
                'exception_class' => $exception::class,
            ]);
            throw new \RuntimeException('Payment gateway verification failed.');
        }

        if ($response->failed()) {
            Log::warning('Platform PayMongo reconciliation failed', [
                'payment_id' => $payment->id,
                'http_status' => $response->status(),
            ]);
            throw new \RuntimeException('Payment gateway verification failed.');
        }

        $attributes = $response->json('data.attributes');
        $providerPayment = is_array($attributes) ? ($attributes['payments'][0] ?? []) : [];
        $providerPaymentAttributes = is_array($providerPayment) ? ($providerPayment['attributes'] ?? []) : [];
        $sessionStatus = strtolower((string) data_get($attributes, 'payment_status'));
        $providerStatus = strtolower((string) data_get($providerPaymentAttributes, 'status'));
        $isPaid = $sessionStatus === 'paid' || in_array($providerStatus, ['paid', 'succeeded', 'captured'], true);

        $providerPaymentId = data_get($providerPayment, 'id');
        $rawAmount = data_get($providerPaymentAttributes, 'amount', data_get($attributes, 'amount_total'));
        $currency = strtoupper((string) data_get($providerPaymentAttributes, 'currency', data_get($attributes, 'currency')));
        if (! $isPaid || ! is_string($providerPaymentId) || trim($providerPaymentId) === '' || ! is_numeric($rawAmount) || $currency === '') {
            throw new \RuntimeException('Payment has not been confirmed by PayMongo.');
        }

        $amount = $this->decimal((string) $rawAmount)
            ->dividedBy('100', 2, RoundingMode::HALF_UP)
            ->__toString();

        $settled = $this->settleFromWebhook(
            checkoutId: $checkoutId,
            providerPaymentId: $providerPaymentId,
            currency: $currency,
            amount: $amount,
        );

        if (! $settled) {
            throw new \RuntimeException('Platform payment record was not found.');
        }

        return $settled;
    }

    public function failFromWebhook(string $checkoutId, string $reason = 'paymongo_payment_failed'): ?PlatformFeePayment
    {
        $payment = PlatformFeePayment::query()->where('provider_checkout_id', $checkoutId)->first();
        if (! $payment) {
            return null;
        }

        $failure = DB::transaction(function () use ($payment, $reason): array {
            $locked = PlatformFeePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'pending') {
                return [$locked, false];
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $locked->update([
                'status' => 'failed',
                'metadata' => [...$metadata, 'failure_reason' => $reason],
            ]);

            PlatformFeePaymentRequest::query()
                ->where('platform_fee_payment_id', $locked->id)
                ->where('status', 'payment_pending')
                ->update([
                    'status' => 'owner_approved',
                    'platform_fee_payment_id' => null,
                ]);

            return [$locked->fresh(), true];
        });

        [$failed, $failedNow] = $failure;
        if (! $failedNow) {
            return $failed;
        }

        try {
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $failed->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Balance Payment Failed',
                message: 'A Platform Balance payment failed or expired and requires execution again.',
                data: ['platform_fee_payment_id' => (int) $failed->id, 'reason' => $reason],
                actionUrl: '/finance/platform-balance',
                priority: 'high',
                requiredPermission: 'access-finance-dashboard',
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance failed-payment notification failed', ['exception_class' => $exception::class]);
        }

        $this->audit('platform_payment_failed', $failed, [
            'shop_id' => $failed->shop_id,
            'reason' => $reason,
        ]);

        return $failed;
    }

    public function applyAvailableCredits(int $shopId): void
    {
        $movements = DB::transaction(function () use ($shopId): array {
            return $this->applyAvailableCreditsLocked($shopId);
        });

        $this->notifyCreditApplications($shopId, $movements);
    }

    public function invalidateIfBalanceChanged(int $shopId): void
    {
        $requests = PlatformFeePaymentRequest::query()
            ->where('shop_id', $shopId)
            ->whereIn('status', ['pending_owner_approval', 'owner_approved'])
            ->get();

        foreach ($requests as $request) {
            $current = $this->balance->summary($shopId);
            if ((string) $current['net_payable'] === (string) $request->net_payable_snapshot) {
                continue;
            }

            $updated = DB::transaction(function () use ($request): ?PlatformFeePaymentRequest {
                $locked = PlatformFeePaymentRequest::query()->lockForUpdate()->find($request->id);
                if (! $locked || ! in_array($locked->status, ['pending_owner_approval', 'owner_approved'], true)) {
                    return null;
                }

                $locked->update([
                    'status' => 'stale',
                    'stale_reason' => 'Platform Balance changed after this request was created.',
                    'stale_at' => now(),
                ]);

                return $locked->fresh();
            });

            if ($updated) {
                $this->audit('platform_payment_request_stale', $updated, [
                    'shop_id' => $updated->shop_id,
                    'reason' => $updated->stale_reason,
                ]);
                $this->notifyOwner(
                    $updated,
                    NotificationType::PLATFORM_BALANCE_ALERT,
                    'Platform Balance Payment Request Stale',
                    'Platform Balance changed. A new payment approval is required.',
                    '/shop-owner/platform-balance',
                    true,
                );
                $this->notifyFinance(
                    $updated,
                    'Platform Balance Payment Request Stale',
                    'Platform Balance changed. A new payment approval is required.',
                );
            }
        }
    }

    /** @return array<string, string> */
    private function positiveSnapshot(int $shopId): array
    {
        $snapshot = $this->balance->summary($shopId);
        if ($this->decimal((string) $snapshot['net_payable'])->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([
                'payment' => ['There is no Platform Balance payable right now.'],
            ]);
        }

        return $snapshot;
    }

    private function assertBusiness(ShopOwner $shop): void
    {
        if ($this->settings->forShop($shop)['shop_type'] !== 'business') {
            throw ValidationException::withMessages([
                'shop' => ['Business payment approval is only available for Business Shops.'],
            ]);
        }
    }

    private function assertIndividual(ShopOwner $shop): void
    {
        if ($this->settings->forShop($shop)['shop_type'] !== 'individual') {
            throw ValidationException::withMessages([
                'shop' => ['Individual Shop Owners pay the Platform Balance directly.'],
            ]);
        }
    }

    private function assertTermsAccepted(ShopOwner $shop): void
    {
        $termsVersion = trim((string) ($this->settings->forShop($shop)['terms_version'] ?? ''));
        if ($termsVersion !== ''
            && ((string) $shop->platform_fee_terms_version !== $termsVersion || $shop->platform_fee_terms_accepted_at === null)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('The current Platform Fee terms must be accepted before payment.');
        }
    }

    private function assertSnapshotCurrent(PlatformFeePaymentRequest $request): void
    {
        $current = $this->balance->summary((int) $request->shop_id);
        if ((string) $current['outstanding_balance'] !== (string) $request->balance_snapshot
            || (string) $current['available_credits'] !== (string) $request->credit_snapshot
            || (string) $current['net_payable'] !== (string) $request->net_payable_snapshot) {
            throw new \RuntimeException('PAYMENT_REQUEST_STALE');
        }
    }

    private function markStale(int $requestId): void
    {
        $request = DB::transaction(function () use ($requestId): ?PlatformFeePaymentRequest {
            $locked = PlatformFeePaymentRequest::query()
                ->whereKey($requestId)
                ->whereIn('status', ['pending_owner_approval', 'owner_approved'])
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return null;
            }

            $locked->update([
                'status' => 'stale',
                'stale_reason' => 'Platform Balance changed after this request was created.',
                'stale_at' => now(),
            ]);

            return $locked->fresh();
        });

        if (! $request) {
            return;
        }

        $this->audit('platform_payment_request_stale', $request, [
            'shop_id' => $request->shop_id,
            'reason' => $request->stale_reason,
        ]);
        $this->notifyOwner(
            $request,
            NotificationType::PLATFORM_BALANCE_ALERT,
            'Platform Balance Payment Request Stale',
            'Platform Balance changed. A new payment approval is required.',
            '/shop-owner/platform-balance',
            true,
        );
        $this->notifyFinance(
            $request,
            'Platform Balance Payment Request Stale',
            'Platform Balance changed. A new payment approval is required.',
        );
    }

    /** @return array{payment: PlatformFeePayment, checkout_url: string|null} */
    private function createCheckout(PlatformFeePayment $payment, string $description): array
    {
        $apiKey = trim((string) config('services.paymongo.secret_key'));
        if ($apiKey === '') {
            $payment->update(['status' => 'failed', 'metadata' => [...(is_array($payment->metadata) ? $payment->metadata : []), 'error' => 'platform_paymongo_not_configured']]);
            throw new \RuntimeException('SoleSpace PayMongo is not configured.');
        }

        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $metadata['payment_id'] = (string) $payment->id;
        $metadata['type'] = 'platform_fee_payment';
        $returnPath = data_get($metadata, 'payment_request_id')
            ? '/finance/platform-balance'
            : '/shop-owner/platform-balance';

        try {
            $response = Http::timeout(10)->connectTimeout(3)->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($apiKey.':'),
                'Idempotency-Key' => (string) $payment->idempotency_key,
            ])->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'success_url' => url($returnPath.'?paymongo_success=1&payment_id='.$payment->id),
                        'cancel_url' => url($returnPath.'?paymongo_failed=1&payment_id='.$payment->id),
                        'description' => $description,
                        'send_email_receipt' => true,
                        'show_description' => true,
                        'show_line_items' => true,
                        'line_items' => [[
                            'currency' => 'PHP',
                            'amount' => $this->cents((string) $payment->amount),
                            'name' => 'SoleSpace Platform Balance',
                            'description' => $description,
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                        'metadata' => $metadata,
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Platform PayMongo checkout request failed', [
                'payment_id' => $payment->id,
                'exception_class' => $exception::class,
            ]);
            $payment->update(['status' => 'failed', 'metadata' => [...$metadata, 'error' => 'gateway_unreachable']]);
            throw new \RuntimeException('Payment gateway error. Please try again.');
        }

        if ($response->failed()) {
            Log::warning('Platform PayMongo checkout failed', [
                'payment_id' => $payment->id,
                'http_status' => $response->status(),
            ]);
            $payment->update(['status' => 'failed', 'metadata' => [...$metadata, 'error' => 'gateway_rejected']]);
            throw new \RuntimeException('Payment gateway error. Please try again.');
        }

        $sessionId = $response->json('data.id');
        $checkoutUrl = $response->json('data.attributes.checkout_url');
        if (! is_string($sessionId) || trim($sessionId) === '' || ! is_string($checkoutUrl) || trim($checkoutUrl) === '') {
            $payment->update(['status' => 'failed', 'metadata' => [...$metadata, 'error' => 'gateway_incomplete_response']]);
            throw new \RuntimeException('Incomplete response from payment gateway.');
        }

        $payment->update([
            'provider_checkout_id' => $sessionId,
            'metadata' => [...$metadata, 'checkout_url' => $checkoutUrl],
        ]);
        $this->audit('platform_payment_checkout_created', $payment->fresh(), [
            'shop_id' => $payment->shop_id,
            'amount' => $payment->amount,
            'provider_checkout_id' => $sessionId,
        ]);

        return [
            'payment' => $payment->fresh(),
            'checkout_url' => $checkoutUrl,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function applyAvailableCreditsLocked(int $shopId): array
    {
        $movements = [];
        $credits = PlatformCreditApplication::query()
            ->where('shop_id', $shopId)
            ->where('source_origin', 'marketplace')
            ->whereIn('status', ['available', 'partially_applied'])
            ->oldest('id')
            ->lockForUpdate()
            ->get();

        $charges = PlatformFeeCharge::query()
            ->where('shop_id', $shopId)
            ->where('source_origin', 'marketplace')
            ->where('status', '!=', 'void')
            ->oldest('id')
            ->lockForUpdate()
            ->get();

        foreach ($credits as $credit) {
            $creditAmount = $this->decimal((string) $credit->credit_amount);
            $remainingCredit = $creditAmount
                ->minus($this->allocatedAmount((int) $credit->id, 'credit', 'platform_credit_application_id'));
            if ($remainingCredit->isLessThanOrEqualTo(0)) {
                $credit->update(['status' => 'applied']);
                continue;
            }

            foreach ($charges as $charge) {
                $remainingCharge = $this->chargeOutstanding($charge);
                if ($remainingCharge->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $amount = $this->min($remainingCredit, $remainingCharge)->toScale(2, RoundingMode::HALF_UP);
                PlatformFeePaymentAllocation::query()->firstOrCreate([
                    'idempotency_key' => "credit:{$credit->id}:charge:{$charge->id}",
                ], [
                    'platform_fee_charge_id' => $charge->id,
                    'platform_credit_application_id' => $credit->id,
                    'allocation_type' => 'credit',
                    'amount' => $amount->__toString(),
                ]);

                $remainingCredit = $remainingCredit->minus($amount);
                if ($remainingCredit->isLessThanOrEqualTo(0)) {
                    break;
                }
            }

            $credit->update([
                'status' => $remainingCredit->isLessThanOrEqualTo(0)
                    ? 'applied'
                    : ($remainingCredit->isLessThan($creditAmount) ? 'partially_applied' : 'available'),
            ]);

            $appliedAmount = $creditAmount->minus($remainingCredit)->toScale(2, RoundingMode::HALF_UP);
            if ($appliedAmount->isGreaterThan(0)) {
                $movements[] = [
                    'credit_id' => (int) $credit->id,
                    'source_type' => (string) $credit->source_type,
                    'source_id' => (int) $credit->source_id,
                    'applied_amount' => $appliedAmount->__toString(),
                    'remaining_amount' => $this->maxZero($remainingCredit)->toScale(2, RoundingMode::HALF_UP)->__toString(),
                ];
            }
        }

        return $movements;
    }

    /** @param array<int, array<string, mixed>> $movements */
    private function notifyCreditApplications(int $shopId, array $movements): void
    {
        if ($movements === []) {
            return;
        }

        $shop = ShopOwner::query()->find($shopId);
        if (! $shop) {
            Log::warning('Platform credit notification skipped because shop was not found', ['shop_id' => $shopId]);
            return;
        }

        $appliedAmount = BigDecimal::zero();
        foreach ($movements as $movement) {
            $appliedAmount = $appliedAmount->plus((string) ($movement['applied_amount'] ?? '0'));
        }
        $appliedAmount = $appliedAmount->toScale(2, RoundingMode::HALF_UP)->__toString();
        $summary = $this->balance->summary($shopId);
        $sourceText = count($movements) === 1
            ? ' from '.ucwords(str_replace(['_', '-'], ' ', (string) ($movements[0]['source_type'] ?? 'refund'))).' #'.(int) ($movements[0]['source_id'] ?? 0)
            : ' from '.count($movements).' credit sources';
        $firstMovement = $movements[0] ?? [];
        $data = [
            'shop_id' => $shopId,
            'applied_amount' => $appliedAmount,
            'credit_count' => count($movements),
            'credits' => $movements,
            'source_type' => count($movements) === 1 ? (string) ($firstMovement['source_type'] ?? '') : null,
            'source_id' => count($movements) === 1 ? (int) ($firstMovement['source_id'] ?? 0) : null,
            'outstanding_balance' => (string) ($summary['outstanding_balance'] ?? '0.00'),
            'net_payable' => (string) ($summary['net_payable'] ?? '0.00'),
        ];

        try {
            $this->notifications->sendToShopOwner(
                shopOwnerId: $shopId,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Credit Applied',
                message: "₱{$appliedAmount} in Platform credit{$sourceText} was applied to your outstanding Platform Balance. Current Outstanding Charges: ₱{$data['outstanding_balance']}.",
                data: $data,
                actionUrl: '/shop-owner/platform-balance',
                priority: 'medium',
            );

            if ($shop->isCompany()) {
                $this->notifications->sendToErpRole(
                    roleName: 'Finance',
                    shopId: $shopId,
                    type: NotificationType::PLATFORM_BALANCE_ALERT,
                    title: 'Platform Credit Applied',
                    message: "₱{$appliedAmount} in Platform credit{$sourceText} was applied to {$shop->business_name}'s outstanding Platform Balance. Current Outstanding Charges: ₱{$data['outstanding_balance']}.",
                    data: $data,
                    actionUrl: '/finance/platform-balance',
                    priority: 'medium',
                    requiredPermission: 'access-finance-dashboard',
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('Platform credit application notification failed', [
                'shop_id' => $shopId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function allocatePaymentLocked(PlatformFeePayment $payment): void
    {
        $remainingPayment = $this->decimal((string) $payment->amount)
            ->minus($this->allocatedAmount((int) $payment->id, 'payment', 'platform_fee_payment_id'));
        if ($remainingPayment->isLessThanOrEqualTo(0)) {
            return;
        }

        $charges = PlatformFeeCharge::query()
            ->where('shop_id', $payment->shop_id)
            ->where('source_origin', 'marketplace')
            ->where('status', '!=', 'void')
            ->oldest('id')
            ->lockForUpdate()
            ->get();

        foreach ($charges as $charge) {
            $remainingCharge = $this->chargeOutstanding($charge);
            if ($remainingCharge->isLessThanOrEqualTo(0)) {
                continue;
            }

            $amount = $this->min($remainingPayment, $remainingCharge)->toScale(2, RoundingMode::HALF_UP);
            PlatformFeePaymentAllocation::query()->firstOrCreate([
                'idempotency_key' => "payment:{$payment->id}:charge:{$charge->id}",
            ], [
                'platform_fee_payment_id' => $payment->id,
                'platform_fee_charge_id' => $charge->id,
                'allocation_type' => 'payment',
                'amount' => $amount->__toString(),
            ]);

            $remainingPayment = $remainingPayment->minus($amount);
            if ($remainingPayment->isLessThanOrEqualTo(0)) {
                return;
            }
        }

        if ($remainingPayment->isGreaterThan(0)) {
            PlatformFeePaymentAllocation::query()->firstOrCreate([
                'idempotency_key' => "payment:{$payment->id}:balance",
            ], [
                'platform_fee_payment_id' => $payment->id,
                'allocation_type' => 'payment',
                'amount' => $remainingPayment->toScale(2, RoundingMode::HALF_UP)->__toString(),
            ]);
        }
    }

    private function chargeOutstanding(PlatformFeeCharge $charge): BigDecimal
    {
        $adjustments = $this->sum(PlatformFeeAdjustment::query()->where('platform_fee_charge_id', $charge->id), 'total_delta');
        $payments = $this->sum(
            PlatformFeePaymentAllocation::query()
                ->where('platform_fee_charge_id', $charge->id)
                ->where('allocation_type', 'payment')
                ->whereHas('payment', fn ($query) => $query->where('status', 'paid')),
            'amount',
        );
        $credits = $this->sum(
            PlatformFeePaymentAllocation::query()
                ->where('platform_fee_charge_id', $charge->id)
                ->where('allocation_type', 'credit')
                ->whereHas('credit', fn ($query) => $query->where('source_origin', 'marketplace')),
            'amount',
        );

        return $this->maxZero($this->decimal((string) $charge->total_charge)->plus($adjustments)->minus($payments)->minus($credits));
    }

    private function allocatedAmount(int $id, string $type, string $column): BigDecimal
    {
        return $this->sum(
            PlatformFeePaymentAllocation::query()
                ->where($column, $id)
                ->where('allocation_type', $type),
            'amount',
        );
    }

    private function sum($query, string $column): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($query->pluck($column) as $value) {
            $total = $total->plus((string) ($value ?? '0'));
        }

        return $total;
    }

    private function min(BigDecimal $left, BigDecimal $right): BigDecimal
    {
        return $left->isLessThanOrEqualTo($right) ? $left : $right;
    }

    private function maxZero(BigDecimal $value): BigDecimal
    {
        return $value->isLessThan(0) ? BigDecimal::zero() : $value;
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of(trim($value) === '' ? '0' : trim($value));
    }

    private function cents(string $amount): int
    {
        return (int) $this->decimal($amount)->multipliedBy('100')->toScale(0, RoundingMode::HALF_UP)->__toString();
    }

    private function notifyOwner(
        PlatformFeePaymentRequest $request,
        NotificationType $type,
        string $title,
        string $message,
        string $actionUrl,
        bool $requiresAction,
    ): void {
        try {
            $this->notifications->sendToShopOwner(
                shopOwnerId: (int) $request->shop_id,
                type: $type,
                title: $title,
                message: $message,
                data: ['payment_request_id' => (int) $request->id],
                actionUrl: $actionUrl,
                priority: 'high',
                groupKey: 'platform-fee-payment-request:'.$request->id,
                requiresAction: $requiresAction,
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance owner notification failed', ['exception_class' => $exception::class]);
        }
    }

    private function notifyFinance(PlatformFeePaymentRequest $request, string $title, string $message): void
    {
        try {
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $request->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: $title,
                message: $message,
                data: ['payment_request_id' => (int) $request->id],
                actionUrl: '/finance/platform-balance',
                priority: 'high',
                groupKey: 'platform-fee-payment-request:'.$request->id.':finance',
                requiresAction: false,
                requiredPermission: 'access-finance-dashboard',
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance finance notification failed', ['exception_class' => $exception::class]);
        }
    }

    private function notifyPaymentReceived(PlatformFeePayment $payment): void
    {
        try {
            $payment->loadMissing('shop');
            $shopName = trim((string) ($payment->shop?->business_name ?? ''));
            $shopName = $shopName !== '' ? $shopName : 'Shop #'.$payment->shop_id;

            $this->notifications->sendToShopOwner(
                shopOwnerId: (int) $payment->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Balance Payment Confirmed',
                message: 'Your full Platform Balance payment has been confirmed.',
                data: ['platform_fee_payment_id' => (int) $payment->id],
                actionUrl: '/shop-owner/platform-balance',
                priority: 'high',
            );
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $payment->shop_id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Balance Payment Confirmed',
                message: 'A Platform Balance payment has been confirmed and allocated.',
                data: ['platform_fee_payment_id' => (int) $payment->id],
                actionUrl: '/finance/platform-balance',
                priority: 'high',
                requiredPermission: 'access-finance-dashboard',
            );
            Notification::notifyAllSuperAdmins(
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Balance Payment Received',
                message: "{$shopName} paid the Platform Balance. The payment was confirmed and allocated to the Platform Fee ledger.",
                actionUrl: '/admin/platform-fees',
                data: [
                    'platform_fee_payment_id' => (int) $payment->id,
                    'shop_id' => (int) $payment->shop_id,
                    'business_name' => $shopName,
                    'amount' => (string) $payment->amount,
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance payment notification failed', ['exception_class' => $exception::class]);
        }
    }

    private function audit(string $event, object $subject, array $properties = []): void
    {
        try {
            activity()
                ->performedOn($subject)
                ->withProperties($properties)
                ->log($event);
        } catch (\Throwable $exception) {
            Log::warning('Platform Balance audit logging failed', [
                'event' => $event,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
