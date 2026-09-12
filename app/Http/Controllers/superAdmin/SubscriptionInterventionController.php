<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privileged\CancelPremiumSubscriptionRequest;
use App\Http\Requests\Privileged\CorrectLegacyPremiumSubscriptionRequest;
use App\Http\Requests\Privileged\RefundPremiumSubscriptionPaymentRequest;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\SuperAdmin;
use App\Services\LegacyPremiumSubscriptionCorrectionService;
use App\Services\PremiumSubscriptionCancellationService;
use App\Services\PremiumSubscriptionRefundService;
use App\Support\PrivilegedFailureResponse;
use Carbon\Carbon;
use DomainException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SubscriptionInterventionController extends Controller
{
    public function __construct(
        private readonly PremiumSubscriptionCancellationService $cancellations,
        private readonly LegacyPremiumSubscriptionCorrectionService $corrections,
        private readonly PremiumSubscriptionRefundService $refunds,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function legacyCorrection(
        CorrectLegacyPremiumSubscriptionRequest $request,
        ShopOwnerSubscription $subscription,
    ): Response
    {
        $admin = $request->user('super_admin');

        if (! $admin instanceof SuperAdmin) {
            return $this->failures->notFound(
                request: $request,
                message: 'The requested subscription was not found.',
                code: 'subscription_not_found',
                forceJson: true,
            );
        }

        try {
            $result = $this->corrections->correct(
                subscription: $subscription,
                actor: $admin,
                request: $request,
                targetStatus: (string) $request->validated('target_status'),
                effectiveEndsAt: Carbon::parse((string) $request->validated('effective_ends_at')),
                reason: (string) $request->validated('correction_reason'),
                notes: $request->validated('correction_notes'),
            );

            return response()->json([
                'success' => true,
                'corrected' => $result['corrected'],
                'replayed' => $result['replayed'],
                'subscription' => $result['subscription'],
            ]);
        } catch (DomainException) {
            return $this->failures->conflict(
                request: $request,
                operation: 'legacy_subscription_correction',
                message: 'The legacy subscription correction conflicts with current state.',
                code: 'legacy_subscription_correction_conflict',
                forceJson: true,
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'legacy_subscription_correction',
                exception: $exception,
                message: 'The legacy subscription correction could not be completed.',
                code: 'legacy_subscription_correction_error',
                forceJson: true,
            );
        }
    }

    public function refund(
        RefundPremiumSubscriptionPaymentRequest $request,
        ShopOwnerSubscriptionPayment $payment,
    ): Response
    {
        $admin = $request->user('super_admin');

        if (! $admin instanceof SuperAdmin) {
            return $this->failures->notFound(
                request: $request,
                message: 'The requested payment was not found.',
                code: 'subscription_payment_not_found',
                forceJson: true,
            );
        }

        try {
            $result = $this->refunds->refund(
                payment: $payment,
                actor: $admin,
                request: $request,
                businessReason: (string) $request->validated('business_reason'),
                providerReason: (string) $request->validated('provider_reason'),
            );

            $attempt = $result['refund'];
            $payload = [
                'success' => $result['outcome'] === 'succeeded' || $result['outcome'] === 'processing',
                'replayed' => $result['replayed'],
                'status' => $result['outcome'],
                'attempt' => $attempt->safeArray(),
            ];

            if ($result['outcome'] === 'succeeded') {
                return response()->json($payload);
            }

            if ($result['outcome'] === 'failed') {
                return response()->json(array_merge($payload, [
                    'success' => false,
                    'message' => 'The payment provider rejected the refund request.',
                    'code' => 'subscription_refund_failed',
                ]), 502);
            }

            if ($result['outcome'] === 'unknown') {
                return response()->json(array_merge($payload, [
                    'success' => false,
                    'message' => 'The refund status is uncertain and requires reconciliation.',
                    'code' => 'subscription_refund_unknown',
                ]), 202);
            }

            return response()->json(array_merge($payload, [
                'message' => 'The refund is being processed by the payment provider.',
            ]), 202);
        } catch (DomainException) {
            return $this->failures->conflict(
                request: $request,
                operation: 'subscription_refund',
                message: 'The subscription refund conflicts with current billing state.',
                code: 'subscription_refund_conflict',
                forceJson: true,
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'subscription_refund',
                exception: $exception,
                message: 'The subscription refund could not be completed.',
                code: 'subscription_refund_error',
                forceJson: true,
            );
        }
    }

    public function cancel(
        CancelPremiumSubscriptionRequest $request,
        ShopOwnerSubscription $subscription,
    ): Response
    {
        $admin = $request->user('super_admin');

        if (! $admin instanceof SuperAdmin) {
            return $this->failures->notFound(
                request: $request,
                message: 'The requested subscription was not found.',
                code: 'subscription_not_found',
                forceJson: true,
            );
        }

        try {
            $result = $this->cancellations->cancelForSuperAdmin(
                subscription: $subscription,
                admin: $admin,
                request: $request,
                reason: (string) $request->validated('cancellation_reason'),
                notes: $request->validated('cancellation_notes'),
            );

            return response()->json([
                'success' => true,
                'replayed' => $result['replayed'],
                'message' => $result['replayed']
                    ? 'Subscription cancellation was already applied.'
                    : 'Subscription cancelled successfully.',
                'subscription' => $result['subscription'],
            ]);
        } catch (DomainException) {
            return $this->failures->conflict(
                request: $request,
                operation: 'subscription_cancel',
                message: 'The subscription cannot be cancelled in its current state.',
                code: 'subscription_cancel_conflict',
                forceJson: true,
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'subscription_cancel',
                exception: $exception,
                message: 'The subscription cancellation could not be completed.',
                code: 'subscription_cancel_error',
                forceJson: true,
            );
        }
    }
}
