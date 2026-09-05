<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\RepairPaymentSession;
use App\Models\RepairRequest;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use App\Services\NotificationService;
use App\Services\PaymentSettlementService;
use App\Services\PremiumSubscriptionRefundService;
use App\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PaymongoWebhookController extends Controller
{
    /**
     * Handle PayMongo webhook events
     */
    public function handle(Request $request)
    {
        try {
            $webhookSecret = (string) config('services.paymongo.webhook_secret');
            if ($webhookSecret !== '') {
                try {
                    $this->verifyWebhookSignature($request);
                } catch (\RuntimeException $e) {
                    Log::warning('Rejected PayMongo webhook due to invalid signature', [
                        'reason' => 'invalid_signature',
                    ]);
                    return response()->json(['message' => 'Invalid webhook signature'], 401);
                }
            } else {
                if (app()->environment('production')) {
                    Log::error('PAYMONGO webhook secret is missing in production');
                    return response()->json(['message' => 'Webhook secret is not configured'], 503);
                }
                Log::warning('PAYMONGO webhook secret is not configured; signature verification skipped');
            }

            $payload = $request->all();

            Log::info('PayMongo Webhook Received', [
                'event_type' => $payload['data']['attributes']['type'] ?? null,
            ]);

            $eventType = $payload['data']['attributes']['type'] ?? null;
            $eventData = $payload['data']['attributes']['data'] ?? null;

            if (!$eventType || !$eventData) {
                Log::warning('Invalid webhook payload structure');
                return response()->json(['message' => 'Invalid payload'], 400);
            }

            // Handle payment link paid event
            if ($eventType === 'link.payment.paid') {
                return $this->handlePaymentPaid($eventData);
            }

            // Handle payment link payment failed
            if ($eventType === 'link.payment.failed') {
                return $this->handlePaymentFailed($eventData);
            }

            // Handle checkout session paid (used for premium subscriptions)
            if ($eventType === 'checkout_session.payment.paid') {
                return $this->handleCheckoutSessionPaid($eventData);
            }

            // Handle checkout session payment failed
            if ($eventType === 'checkout_session.payment.failed') {
                return $this->handleCheckoutSessionFailed($eventData);
            }

            if (is_string($eventType) && str_contains($eventType, 'refund')) {
                return $this->handleRefundEvent($eventType, $eventData);
            }

            return response()->json(['message' => 'Event received'], 200);

        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'exception_class' => $e::class,
            ]);
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    /**
     * Handle successful payment
     */
    private function handlePaymentPaid($eventData)
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentLinkId = $attributes['payment_link_id'] ?? null;
        $paymentId = $eventData['id'] ?? null;
        $amount = $attributes['amount'] ?? 0;

        if (!$paymentLinkId) {
            Log::error('No payment_link_id in webhook data');
            return response()->json(['message' => 'Missing payment_link_id'], 400);
        }

        // Try to find order by payment_link_id
        $order = Order::where('paymongo_link_id', $paymentLinkId)->first();

        if ($order) {
            // Handle product order payment
            return $this->handleOrderPayment($order, $paymentId);
        }

        $repairSession = RepairPaymentSession::query()
            ->with('repairRequest')
            ->where('provider_link_id', $paymentLinkId)
            ->first();

        if ($repairSession?->repairRequest) {
            return $this->handleRepairPayment($repairSession->repairRequest, $paymentId, $repairSession);
        }

        // Legacy repair links created before persisted payment sessions.
        $repairRequest = RepairRequest::where('paymongo_link_id', $paymentLinkId)->first();

        if ($repairRequest) {
            // Handle repair request payment
            return $this->handleRepairPayment($repairRequest, $paymentId);
        }

        Log::warning('Order or RepairRequest not found for payment_link_id', ['payment_link_id' => $paymentLinkId]);
        return response()->json(['message' => 'Order or RepairRequest not found'], 404);
    }

    /**
     * Handle product order payment
     */
    private function handleOrderPayment($order, $paymentId)
    {
        $settlement = app(PaymentSettlementService::class)
            ->settleOrderPaid($order, (string) $paymentId, true);

        $result = $settlement['result'] ?? 'settled';
        $settledOrder = $settlement['model'] ?? $order;

        if ($result === 'already_settled') {
            Log::info('Order payment webhook ignored (already paid)', [
                'order_id' => $settledOrder->id,
                'order_number' => $settledOrder->order_number,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Already paid'], 200);
        }

        if ($result === 'expired') {
            Log::warning('Late paid webhook ignored for expired order payment session', [
                'order_id' => $settledOrder->id,
                'order_number' => $settledOrder->order_number,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Expired payment session'], 200);
        }

        // Log payment processing
        activity()
            ->performedOn($settledOrder)
            ->withProperties([
                'order_number' => $settledOrder->order_number,
                'customer_name' => $settledOrder->customer_name ?? 'N/A',
                'amount_paid' => $settledOrder->total_amount,
                'payment_id' => $paymentId,
                'payment_method' => 'PayMongo',
                'payment_status' => 'paid',
            ])
            ->log("Order payment processed: {$settledOrder->order_number} - ₱{$settledOrder->total_amount}");

        Log::info('Order payment confirmed', [
            'order_id' => $settledOrder->id,
            'order_number' => $settledOrder->order_number,
            'payment_id' => $paymentId,
            'result' => $result,
        ]);

        // You can also send confirmation email here
        // Mail::to($order->customer_email)->send(new OrderConfirmation($order));

        return response()->json(['message' => 'Payment processed'], 200);
    }

    /**
     * Handle repair request payment
     */
    private function handleRepairPayment($repairRequest, $paymentId, ?RepairPaymentSession $session = null)
    {
        $settlement = app(PaymentSettlementService::class)
            ->settleRepairPaid($repairRequest, (string) $paymentId, true, $session);

        $result = $settlement['result'] ?? 'settled';
        $settledRepair = $settlement['model'] ?? $repairRequest;

        if ($result === 'already_settled') {
            Log::info('Repair payment webhook ignored (already completed)', [
                'repair_id' => $settledRepair->id,
                'request_id' => $settledRepair->request_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Already completed'], 200);
        }

        if ($result === 'expired') {
            Log::warning('Late paid webhook ignored for expired repair payment session', [
                'repair_id' => $settledRepair->id,
                'request_id' => $settledRepair->request_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Expired payment session'], 200);
        }

        if ($result === 'not_due') {
            Log::info('Repair payment webhook ignored (no payable phase due)', [
                'repair_id' => $settledRepair->id,
                'request_id' => $settledRepair->request_id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'No payable phase due'], 200);
        }

        if ($result === 'reconciliation') {
            Log::warning('Repair delivery payment requires reconciliation', [
                'repair_id' => $settledRepair->id,
                'request_id' => $settledRepair->request_id,
                'payment_id' => $paymentId,
                'payment_session_id' => $session?->id,
            ]);

            return response()->json(['message' => 'Repair payment requires reconciliation'], 200);
        }

        $phase = (string) ($settlement['phase'] ?? '');
        $phaseLabel = $phase === 'full_upfront'
            ? 'full upfront payment'
            : ($phase === 'remaining_balance' ? 'remaining balance (50%)' : 'deposit (50%)');
        $policy = (string) ($settlement['policy'] ?? 'deposit_50');

        activity()
            ->performedOn($settledRepair)
            ->withProperties([
                'request_id'     => $settledRepair->request_id,
                'policy'         => $policy,
                'phase'          => $phaseLabel,
                'payment_id'     => $paymentId,
                'payment_method' => 'PayMongo',
                'payment_status' => $settledRepair->fresh()->payment_status,
            ])
            ->log("Repair payment processed ({$phaseLabel}): {$settledRepair->request_id}");

        Log::info("Repair payment webhook handled", [
            'repair_id'  => $settledRepair->id,
            'request_id' => $settledRepair->request_id,
            'policy'     => $policy,
            'phase'      => $phaseLabel,
            'payment_id' => $paymentId,
            'result'     => $result,
        ]);

        return response()->json(['message' => 'Repair payment processed'], 200);
    }

    /**
     * Handle failed payment
     */
    private function handlePaymentFailed($eventData)
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentLinkId = $attributes['payment_link_id'] ?? null;

        if (!$paymentLinkId) {
            return response()->json(['message' => 'Missing payment_link_id'], 400);
        }

        $order = Order::where('paymongo_link_id', $paymentLinkId)->first();
        $settlementService = app(PaymentSettlementService::class);

        if ($order) {
            Log::info('Payment failed for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            $settlementService->recordOrderPaymentFailure($order, 'paymongo_payment_failed');

            return response()->json(['message' => 'Order payment failure recorded'], 200);
        }

        $repairRequest = RepairRequest::where('paymongo_link_id', $paymentLinkId)->first();

        if ($repairRequest) {
            Log::info('Payment failed for repair request', [
                'repair_id' => $repairRequest->id,
                'request_id' => $repairRequest->request_id,
            ]);

            $settlementService->recordRepairPaymentFailure($repairRequest, 'paymongo_payment_failed');

            return response()->json(['message' => 'Repair payment failure recorded'], 200);
        }

        return response()->json(['message' => 'Payment failure recorded'], 200);
    }

    /**
     * Verify webhook signature (optional but recommended)
     */
    private function verifyWebhookSignature(Request $request): void
    {
        $signatureHeader = trim((string) $request->header('Paymongo-Signature'));
        $payload = $request->getContent();
        $webhookSecret = (string) config('services.paymongo.webhook_secret');

        if ($signatureHeader === '' || $webhookSecret === '') {
            throw new \RuntimeException('Missing webhook signature or secret');
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($name !== null && $value !== null) {
                $parts[trim($name)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? null;
        if (! is_string($timestamp) || ! ctype_digit($timestamp)) {
            throw new \RuntimeException('Webhook signature format is invalid');
        }

        $tolerance = max(1, (int) config('services.paymongo.webhook_tolerance_seconds', 300));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new \RuntimeException('Webhook signature timestamp is outside the allowed window');
        }

        $decoded = json_decode($payload, true);
        $liveMode = is_array($decoded)
            && (bool) data_get($decoded, 'data.attributes.livemode', false);
        $providedSignature = $liveMode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');

        if (! is_string($providedSignature) || $providedSignature === '') {
            throw new \RuntimeException('Webhook signature format is invalid');
        }

        $computedSignature = hash_hmac('sha256', $timestamp.'.'.$payload, $webhookSecret);

        if (! hash_equals($computedSignature, $providedSignature)) {
            throw new \RuntimeException('Invalid webhook signature');
        }
    }

    /**
     * Handle checkout_session.payment.paid — activates a premium subscription.
     *
     * Lookup order:
     *   1. By paymongo_session_id stored at checkout creation.
     *   2. Fallback: by subscription_id embedded in session metadata.
     *
     * Idempotency: the row is locked inside a DB transaction; only rows in
     * 'pending' status are transitioned — all other statuses are skipped.
     */
    private function handleCheckoutSessionPaid($eventData)
    {
        $sessionId  = $eventData['id'] ?? null;
        $attributes = $eventData['attributes'] ?? [];
        $metadata   = $attributes['metadata'] ?? [];
        $payments   = $attributes['payments'] ?? [];
        $paymentId  = $payments[0]['id'] ?? null;
        $paymentAttributes = $payments[0]['attributes'] ?? [];
        $paidAmount = $this->extractPaidAmount($attributes, $paymentAttributes);
        $providerCurrency = strtoupper((string) ($paymentAttributes['currency'] ?? $attributes['currency'] ?? ''));

        $repairSession = RepairPaymentSession::query()
            ->with('repairRequest')
            ->where('provider_link_id', $sessionId)
            ->first();

        if ($repairSession?->repairRequest) {
            return $this->handleRepairPayment($repairSession->repairRequest, $paymentId, $repairSession);
        }

        $payment = $this->resolveSubscriptionPayment($sessionId, $metadata);
        if (!$payment) {
            Log::warning('Premium payment record not found for checkout_session.payment.paid', [
                'session_id' => $sessionId,
            ]);

            return response()->json(['message' => 'Payment record not found'], 200);
        }

        // Resolve the subscription record (outside the transaction is fine for the lookup)
        $subscription = $this->resolveSubscription($sessionId, $metadata);

        if (!$subscription || (int) $payment->subscription_id !== (int) $subscription->id) {
            Log::warning('Premium subscription not found for checkout_session.payment.paid', [
                'session_id' => $sessionId,
            ]);
            return response()->json(['message' => 'Subscription not found'], 200);
        }

        $activated = DB::transaction(function () use ($subscription, $payment, $sessionId, $paymentId, $paidAmount, $providerCurrency, $metadata) {
            // Lock the specific row; prevents duplicate activation under concurrent webhooks
            $lockedPayment = ShopOwnerSubscriptionPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = ShopOwnerSubscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->firstOrFail();
            $metadataSubscriptionId = array_key_exists('subscription_id', $metadata)
                && is_scalar($metadata['subscription_id'])
                ? (string) $metadata['subscription_id']
                : (string) $locked->id;
            $metadataShopOwnerId = array_key_exists('shop_owner_id', $metadata)
                && is_scalar($metadata['shop_owner_id'])
                ? (string) $metadata['shop_owner_id']
                : (string) $locked->shop_owner_id;

            if ((int) $lockedPayment->subscription_id !== (int) $locked->id
                || (int) $lockedPayment->shop_owner_id !== (int) $locked->shop_owner_id
                || ($sessionId && $lockedPayment->paymongo_session_id && $lockedPayment->paymongo_session_id !== $sessionId)
                || ($sessionId && $locked->paymongo_session_id && $locked->paymongo_session_id !== $sessionId)
                || $metadataSubscriptionId !== (string) $locked->id
                || $metadataShopOwnerId !== (string) $locked->shop_owner_id
                || ! is_string($paymentId)
                || trim($paymentId) === ''
                || ($paidAmount === null)
                || $providerCurrency === ''
                || $providerCurrency !== strtoupper((string) $lockedPayment->currency)
                || abs($paidAmount - (float) $lockedPayment->amount_due) > 0.009) {
                Log::warning('Premium payment webhook failed local verification', [
                    'subscription_id' => $locked->id,
                    'payment_record_id' => $lockedPayment->id,
                    'session_id' => $sessionId,
                ]);

                return false;
            }

            // Idempotency: already active — nothing to do
            if ($lockedPayment->status === 'paid' || $locked->status === 'active') {
                Log::info('Premium subscription already active — duplicate webhook ignored', [
                    'subscription_id' => $locked->id,
                    'session_id'      => $sessionId,
                ]);
                return false;
            }

            // Guard: only activate subscriptions that are in the expected pre-payment states.
            // 'expired', 'cancelled' must never be reactivated through a payment webhook.
            if ($lockedPayment->status !== 'pending' || $locked->status !== 'pending') {
                Log::warning('Premium subscription in non-activatable state — skipping', [
                    'subscription_id' => $locked->id,
                    'current_status'  => $locked->status,
                    'session_id'      => $sessionId,
                ]);
                return false;
            }

            $startsAt = now();
            $locked->loadMissing('premiumPlan');
            $durationDays = max(1, (int) ($locked->premiumPlan?->duration_days ?? 30));
            $endsAt = $startsAt->copy()->addDays($durationDays);

            $updatePayload = [
                'status'                => 'active',
                'paymongo_payment_id'   => $paymentId,
                'paid_amount'           => $paidAmount,
                'starts_at'             => $startsAt,
                'ends_at'               => $endsAt,
            ];

            if (
                Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')
            ) {
                $updatePayload['auto_renew'] = true;
                $updatePayload['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED;
            }

            $lockedPayment->update([
                'paymongo_payment_id' => $paymentId,
                'status' => 'paid',
                'amount_paid' => $paidAmount,
                'paid_at' => now(),
            ]);
            $locked->update($updatePayload);

            // Upgrade path: once the new paid subscription is active, immediately end
            // access for the previous subscription and clear stale pending downgrade data.
            if ($locked->replaces_subscription_id) {
                $source = ShopOwnerSubscription::query()
                    ->where('id', (int) $locked->replaces_subscription_id)
                    ->lockForUpdate()
                    ->first();

                if ($source && $source->status === 'active') {
                    $sourceUpdate = [
                        'status' => 'cancelled',
                        'ends_at' => now(),
                    ];

                    if (
                        Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                        && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')
                    ) {
                        $sourceUpdate['auto_renew'] = false;
                        $sourceUpdate['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED;
                    }

                    if (Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                        $sourceUpdate['pending_premium_plan_id'] = null;
                    }

                    if (Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                        $sourceUpdate['pending_plan_effective_at'] = null;
                    }

                    $source->update($sourceUpdate);
                }
            }

            activity()
                ->performedOn($locked)
                ->withProperties([
                    'subscription_id' => $locked->id,
                    'shop_owner_id'   => $locked->shop_owner_id,
                    'plan_code'       => $locked->plan_code,
                    'starts_at'       => $startsAt->toDateTimeString(),
                    'ends_at'         => $endsAt->toDateTimeString(),
                    'payment_id'      => $paymentId,
                    'session_id'      => $sessionId,
                    'paid_amount'     => $paidAmount,
                ])
                ->log('Premium subscription activated: ' . $locked->plan_code);

            Log::info('Premium subscription activated', [
                'subscription_id' => $locked->id,
                'shop_owner_id'   => $locked->shop_owner_id,
                'plan_code'       => $locked->plan_code,
                'ends_at'         => $endsAt->toDateTimeString(),
                'payment_id'      => $paymentId,
                'session_id'      => $sessionId,
            ]);

            return $locked->fresh();
        });

        // Send in-app + email notification to the shop owner (outside the transaction,
        // so a notification failure never rolls back the subscription activation)
        if ($activated) {
            try {
                $appUrl    = rtrim(config('app.url'), '/');
                $planLabel = ucfirst($activated->plan_code);

                app(NotificationService::class)->sendToShopOwner(
                    $activated->shop_owner_id,
                    NotificationType::PAYMENT_RECEIVED,
                    'Premium Subscription Activated',
                    "Your SoleSpace {$planLabel} subscription is now active and will continue until you cancel it.",
                    [
                        'subscription_id' => $activated->id,
                        'plan_code'       => $activated->plan_code,
                        'ends_at'         => $activated->ends_at?->toISOString(),
                    ],
                    $appUrl . '/shop-owner/premium/benefits',
                    'high'
                );
            } catch (\Exception $e) {
                // Never let a notification error surface as a webhook failure
                Log::error('Failed to send premium activation notification', [
                    'subscription_id' => $activated->id,
                    'exception_class' => $e::class,
                ]);
            }
        }

        return response()->json(['message' => $activated ? 'Subscription activated' : 'Already processed'], 200);
    }

    /**
     * Handle checkout_session.payment.failed — marks the pending subscription as failed.
     *
     * Idempotent: only updates rows that are currently 'pending'.
     */
    private function handleCheckoutSessionFailed($eventData)
    {
        $sessionId = $eventData['id'] ?? null;
        $metadata  = $eventData['attributes']['metadata'] ?? [];

        $repairSession = RepairPaymentSession::query()
            ->with('repairRequest')
            ->where('provider_link_id', $sessionId)
            ->first();

        if ($repairSession?->repairRequest) {
            DB::transaction(function () use ($repairSession): void {
                $lockedSession = RepairPaymentSession::query()->lockForUpdate()->findOrFail($repairSession->id);
                if ($lockedSession->status !== 'pending') {
                    return;
                }

                $lockedSession->update([
                    'status' => 'failed',
                    'resolved_at' => now(),
                ]);
                app(PaymentSettlementService::class)->recordRepairPaymentFailure(
                    $repairSession->repairRequest,
                    'paymongo_payment_failed',
                );
            });

            return response()->json(['message' => 'Repair payment failure recorded'], 200);
        }

        $payment = $this->resolveSubscriptionPayment($sessionId, $metadata);
        $subscription = $this->resolveSubscription($sessionId, $metadata);

        if (!$subscription || !$payment || (int) $payment->subscription_id !== (int) $subscription->id) {
            // Nothing to update — return 200 to stop PayMongo retrying
            return response()->json(['message' => 'Subscription not found — no action'], 200);
        }

        if (
            (array_key_exists('subscription_id', $metadata)
                && (! is_scalar($metadata['subscription_id'])
                    || (string) $metadata['subscription_id'] !== (string) $subscription->id))
            || (array_key_exists('shop_owner_id', $metadata)
                && (! is_scalar($metadata['shop_owner_id'])
                    || (string) $metadata['shop_owner_id'] !== (string) $subscription->shop_owner_id))
        ) {
            return response()->json(['message' => 'Subscription webhook binding mismatch'], 200);
        }

        $failed = DB::transaction(function () use ($subscription, $payment, $sessionId) {
            $lockedPayment = ShopOwnerSubscriptionPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = ShopOwnerSubscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedPayment->shop_owner_id !== (int) $locked->shop_owner_id
                || $lockedPayment->status !== 'pending' || $locked->status !== 'pending'
                || ($sessionId && $lockedPayment->paymongo_session_id && $lockedPayment->paymongo_session_id !== $sessionId)) {
                // Already resolved (active, failed, cancelled, expired) — skip
                return false;
            }

            $lockedPayment->update(['status' => 'failed']);
            $locked->update(['status' => 'failed']);

            activity()
                ->performedOn($locked)
                ->withProperties([
                    'subscription_id' => $locked->id,
                    'shop_owner_id'   => $locked->shop_owner_id,
                    'plan_code'       => $locked->plan_code,
                    'session_id'      => $sessionId,
                    'payment_record_id' => $lockedPayment->id,
                ])
                ->log('Premium subscription payment failed: ' . $locked->plan_code);

            Log::info('Premium subscription marked failed via webhook', [
                'subscription_id' => $locked->id,
                'shop_owner_id'   => $locked->shop_owner_id,
                'session_id'      => $sessionId,
            ]);

            return true;
        });

        // Notify the shop owner so they know to retry
        if ($failed) {
            try {
            $appUrl    = rtrim(config('app.url'), '/');
            $planLabel = ucfirst($subscription->plan_code);

            app(NotificationService::class)->sendToShopOwner(
                $subscription->shop_owner_id,
                NotificationType::PAYMENT_FAILED,
                'Premium Subscription Payment Failed',
                "Your payment for the SoleSpace {$planLabel} plan was not completed. Please try again.",
                ['subscription_id' => $subscription->id, 'plan_code' => $subscription->plan_code],
                $appUrl . '/shop-owner/premium/benefits',
                'high'
            );
            } catch (\Exception $e) {
                Log::error('Failed to send premium payment-failed notification', [
                    'subscription_id' => $subscription->id,
                    'exception_class' => $e::class,
                ]);
            }
        }

        return response()->json(['message' => $failed ? 'Failure recorded' : 'Already processed'], 200);
    }

    /**
     * Resolve a ShopOwnerSubscription from a PayMongo checkout session event.
     *
     * Priority:
     *   1. paymongo_session_id column (most reliable — set at checkout creation)
     *   2. subscription_id in session metadata (fallback)
     *
     * @param array<string, mixed> $metadata
     */
    private function resolveSubscriptionPayment(?string $sessionId, array $metadata): ?ShopOwnerSubscriptionPayment
    {
        if (!empty($metadata['payment_record_id'])) {
            return ShopOwnerSubscriptionPayment::query()
                ->whereKey((int) $metadata['payment_record_id'])
                ->first();
        }

        if ($sessionId) {
            $matches = ShopOwnerSubscriptionPayment::query()
                ->where('paymongo_session_id', $sessionId)
                ->limit(2)
                ->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        if (!empty($metadata['subscription_id'])) {
            $matches = ShopOwnerSubscriptionPayment::query()
                ->where('subscription_id', (int) $metadata['subscription_id'])
                ->where('status', 'pending')
                ->limit(2)
                ->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }

    private function resolveSubscription(?string $sessionId, array $metadata): ?ShopOwnerSubscription
    {
        if ($sessionId) {
            $matches = ShopOwnerSubscription::query()
                ->where('paymongo_session_id', $sessionId)
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        if (isset($metadata['subscription_id'])) {
            return ShopOwnerSubscription::find((int) $metadata['subscription_id']);
        }

        return null;
    }

    private function extractPaidAmount(array $sessionAttributes, array $paymentAttributes): ?float
    {
        $rawAmount = $paymentAttributes['amount']
            ?? $sessionAttributes['payments'][0]['attributes']['amount']
            ?? $sessionAttributes['amount_total']
            ?? null;

        if (!is_numeric($rawAmount)) {
            return null;
        }

        // PayMongo amounts are usually in centavos
        return round(((float) $rawAmount) / 100, 2);
    }

    private function handleRefundEvent(string $eventType, array $eventData)
    {
        $attributes = $eventData['attributes'] ?? [];

        $refundId = $eventData['id']
            ?? ($attributes['id'] ?? null);

        $paymentId = $attributes['payment_id']
            ?? ($attributes['data']['attributes']['payment_id'] ?? null)
            ?? null;

        $rawStatus = $attributes['status']
            ?? ($attributes['data']['attributes']['status'] ?? null)
            ?? null;

        $status = strtolower((string) $rawStatus);

        $subscriptionRefund = $this->resolveSubscriptionRefundAttempt($refundId, $paymentId);
        if ($subscriptionRefund) {
            $trustedPaymentId = (string) $subscriptionRefund->payment?->paymongo_payment_id;
            if ($paymentId && $trustedPaymentId !== (string) $paymentId) {
                Log::warning('Subscription refund webhook payment binding mismatch', [
                    'refund_id' => $refundId,
                    'payment_id' => $paymentId,
                ]);

                return response()->json(['message' => 'Refund event ignored'], 200);
            }

            $outcome = $eventType === 'payment.refunded'
                ? 'succeeded'
                : match ($status) {
                    'succeeded', 'completed', 'paid' => 'succeeded',
                    'pending', 'processing' => 'processing',
                    'failed', 'canceled', 'cancelled' => 'failed',
                    default => 'unknown',
                };

            $result = app(PremiumSubscriptionRefundService::class)->applyProviderWebhook(
                attempt: $subscriptionRefund,
                result: [
                    'outcome' => $outcome,
                    'refund_id' => $refundId,
                    'amount' => is_numeric($attributes['amount'] ?? null) ? (int) $attributes['amount'] : null,
                    'currency' => isset($attributes['currency']) ? strtoupper((string) $attributes['currency']) : null,
                    'payment_id' => $paymentId,
                    'failure_code' => $outcome === 'failed' ? 'provider_refund_failed' : null,
                ],
                request: request(),
            );

            return response()->json([
                'message' => 'Subscription refund updated',
                'status' => $result['outcome'],
            ], 200);
        }

        if (!$refundId && !$paymentId) {
            Log::warning('Refund webhook missing identifiers', [
                'event_type' => $eventType,
            ]);

            return response()->json(['message' => 'Missing refund identifiers'], 200);
        }

        $refund = OrderRefund::query()
            ->when($refundId, fn ($query) => $query->orWhere('paymongo_refund_id', $refundId))
            ->when($paymentId, fn ($query) => $query->orWhere('paymongo_payment_id', $paymentId))
            ->orderByDesc('id')
            ->first();

        if (!$refund) {
            Log::warning('Refund webhook could not map to order_refunds row', [
                'event_type' => $eventType,
                'refund_id' => $refundId,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Refund record not found'], 200);
        }

        $order = Order::find($refund->order_id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 200);
        }

        $settlementService = app(PaymentSettlementService::class);

        if (in_array($status, ['succeeded', 'completed', 'paid'], true)) {
            $refund->update([
                'status' => 'succeeded',
                'paymongo_refund_id' => $refundId ?? $refund->paymongo_refund_id,
                'refunded_at' => $refund->refunded_at ?? now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            $settlementService->settleOrderRefunded(
                order: $order,
                refundId: $refundId ?? $refund->paymongo_refund_id,
                reason: $refund->reason_code,
                note: $refund->reason_note,
            );

            return response()->json(['message' => 'Refund settled'], 200);
        }

        if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            $refund->update([
                'status' => 'failed',
                'paymongo_refund_id' => $refundId ?? $refund->paymongo_refund_id,
                'failure_reason' => 'paymongo_refund_failed',
                'failed_at' => now(),
            ]);

            $settlementService->recordOrderRefundFailure($order, 'paymongo_refund_failed');

            return response()->json(['message' => 'Refund failure recorded'], 200);
        }

        $refund->update([
            'status' => 'processing',
            'paymongo_refund_id' => $refundId ?? $refund->paymongo_refund_id,
        ]);

        return response()->json(['message' => 'Refund processing'], 200);
    }

    private function resolveSubscriptionRefundAttempt(?string $refundId, ?string $paymentId): ?ShopOwnerSubscriptionRefund
    {
        if (! $refundId && ! $paymentId) {
            return null;
        }

        $query = ShopOwnerSubscriptionRefund::query()->with('payment');
        if ($refundId) {
            $query->where(function ($query) use ($refundId, $paymentId): void {
                $query->where('provider_refund_id', $refundId);

                if ($paymentId) {
                    $query->orWhere(function ($query) use ($paymentId): void {
                        $query->whereNull('provider_refund_id')
                            ->whereHas(
                                'payment',
                                fn ($paymentQuery) => $paymentQuery->where('paymongo_payment_id', $paymentId),
                            );
                    });
                }
            });
        } elseif ($paymentId) {
            $query->whereHas(
                'payment',
                fn ($paymentQuery) => $paymentQuery->where('paymongo_payment_id', $paymentId),
            );
        }

        return $query->latest('id')->first();
    }
}
