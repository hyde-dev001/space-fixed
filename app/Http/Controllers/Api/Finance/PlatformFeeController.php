<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentRequest;
use App\Models\PlatformReliabilityScore;
use App\Models\ShopOwner;
use App\Services\PlatformBalanceService;
use App\Services\PlatformFeePaymentService;
use App\Services\PlatformFeeSettingsResolver;
use App\Services\PlatformReliabilityService;
use App\Support\Finance\FinanceShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PlatformFeeController extends Controller
{
    public function __construct(
        private readonly FinanceShopContext $shopContext,
        private readonly PlatformBalanceService $balance,
        private readonly PlatformFeePaymentService $payments,
        private readonly PlatformFeeSettingsResolver $settings,
        private readonly PlatformReliabilityService $reliability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->payload($this->shopContext->id($request));
    }

    public function ownerIndex(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);

        return $this->payload((int) $owner->getKey());
    }

    public function createPaymentRequest(Request $request): JsonResponse
    {
        $shopId = $this->shopContext->id($request);

        try {
            $paymentRequest = $this->payments->createBusinessRequest(
                shopId: $shopId,
                financeUserId: (int) $request->user('user')->getKey(),
                idempotencyKey: $request->header('Idempotency-Key'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['payment_request' => $paymentRequest], 201);
    }

    public function executePaymentRequest(Request $request, int $id): JsonResponse
    {
        $shopId = $this->shopContext->id($request);
        $paymentRequest = PlatformFeePaymentRequest::query()
            ->where('shop_id', $shopId)
            ->findOrFail($id);

        try {
            $result = $this->payments->executeBusinessRequest(
                request: $paymentRequest,
                financeUserId: (int) $request->user('user')->getKey(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            if ($paymentRequest->fresh()->status === 'stale') {
                return response()->json([
                    'message' => 'Platform Balance changed. A new payment approval is required.',
                    'error' => 'PAYMENT_REQUEST_STALE',
                ], 409);
            }
            return response()->json([
                'message' => $exception->getMessage() === 'PAYMENT_REQUEST_STALE'
                    ? 'Platform Balance changed. A new payment approval is required.'
                    : $exception->getMessage(),
                'error' => $exception->getMessage() === 'PAYMENT_REQUEST_STALE'
                    ? 'PAYMENT_REQUEST_STALE'
                    : 'PLATFORM_PAYMENT_FAILED',
            ], $exception->getMessage() === 'PAYMENT_REQUEST_STALE' ? 409 : 422);
        }

        return response()->json([
            'payment' => [
                'id' => $result['payment']->id,
                'amount' => (string) $result['payment']->amount,
                'status' => $result['payment']->status,
                'checkout_url' => $result['checkout_url'],
            ],
            'payment_request' => $paymentRequest->fresh(),
        ]);
    }

    public function ownerPaymentRequests(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);

        return response()->json([
            'payment_requests' => PlatformFeePaymentRequest::query()
                ->where('shop_id', $owner->id)
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function approvePaymentRequest(Request $request, int $id): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);
        $paymentRequest = PlatformFeePaymentRequest::query()
            ->where('shop_id', $owner->id)
            ->findOrFail($id);

        try {
            return response()->json([
                'payment_request' => $this->payments->approve($paymentRequest, $owner),
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage() === 'PAYMENT_REQUEST_STALE'
                    ? 'Platform Balance changed. A new payment approval is required.'
                    : $exception->getMessage(),
                'error' => $exception->getMessage() === 'PAYMENT_REQUEST_STALE' ? 'PAYMENT_REQUEST_STALE' : 'PAYMENT_REQUEST_INVALID',
            ], 409);
        }
    }

    public function rejectPaymentRequest(Request $request, int $id): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);
        $paymentRequest = PlatformFeePaymentRequest::query()
            ->where('shop_id', $owner->id)
            ->findOrFail($id);

        return response()->json([
            'payment_request' => $this->payments->reject(
                request: $paymentRequest,
                owner: $owner,
                reason: $request->input('reason'),
            ),
        ]);
    }

    public function ownerPay(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);

        try {
            $result = $this->payments->createIndividualPayment($owner);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'payment' => [
                'id' => $result['payment']->id,
                'amount' => (string) $result['payment']->amount,
                'status' => $result['payment']->status,
                'checkout_url' => $result['checkout_url'],
            ],
        ]);
    }

    public function ownerReconcile(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);

        return $this->reconcileForShop((int) $owner->id, $request);
    }

    public function reconcile(Request $request): JsonResponse
    {
        return $this->reconcileForShop($this->shopContext->id($request), $request);
    }

    private function reconcileForShop(int $shopId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $payment = PlatformFeePayment::query()
            ->where('shop_id', $shopId)
            ->where('status', 'pending')
            ->whereNotNull('provider_checkout_id')
            ->when(
                array_key_exists('payment_id', $validated),
                fn ($query) => $query->whereKey($validated['payment_id']),
            )
            ->latest('id')
            ->first();

        if (! $payment) {
            return response()->json(['payment' => null, 'message' => 'No pending Platform Balance payment needs reconciliation.']);
        }

        try {
            $settled = $this->payments->reconcileFromProvider($payment);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => 'PLATFORM_PAYMENT_RECONCILIATION_FAILED',
            ], 422);
        }

        return response()->json([
            'payment' => [
                'id' => $settled->id,
                'amount' => (string) $settled->amount,
                'status' => $settled->status,
                'provider_payment_id' => $settled->provider_payment_id,
            ],
        ]);
    }

    public function acceptTerms(Request $request): JsonResponse
    {
        $owner = $request->user('shop_owner');
        abort_unless($owner instanceof ShopOwner, 403);
        $config = $this->settings->forShop($owner);
        $version = trim((string) ($config['terms_version'] ?? ''));
        if ($version === '') {
            return response()->json(['message' => 'No Platform Fee terms are currently configured.'], 409);
        }

        $owner->update([
            'platform_fee_terms_version' => $version,
            'platform_fee_terms_accepted_by' => $owner->id,
            'platform_fee_terms_accepted_at' => now(),
        ]);

        return response()->json([
            'terms_version' => $version,
            'accepted_at' => $owner->platform_fee_terms_accepted_at?->toISOString(),
        ]);
    }

    private function payload(int $shopId): JsonResponse
    {
        $shop = ShopOwner::query()->findOrFail($shopId);
        $config = $this->settings->forShop($shop);
        $latestScore = $this->reliability->latest($shopId);
        $charges = PlatformFeeCharge::query()
            ->where('shop_id', $shopId)
            ->where('source_origin', 'marketplace')
            ->latest('id')
            ->limit(100)
            ->get([
                'id',
                'shop_id',
                'source_type',
                'source_id',
                'source_origin',
                'fee_base',
                'fee_rate',
                'platform_fee_amount',
                'vat_rate',
                'vat_amount',
                'total_charge',
                'status',
                'finalized_at',
            ]);

        return response()->json([
            'balance' => $this->balance->summary($shopId),
            'charges' => $charges,
            'payment_requests' => PlatformFeePaymentRequest::query()
                ->where('shop_id', $shopId)
                ->latest('id')
                ->limit(20)
                ->get(),
            'reliability' => [
                'latest' => $latestScore,
                'history' => PlatformReliabilityScore::query()
                    ->where('shop_owner_id', $shopId)
                    ->latest('score_date')
                    ->limit(30)
                    ->get(),
            ],
            'terms' => [
                'version' => $config['terms_version'],
                'text' => $config['terms_text'],
                'accepted' => trim((string) $shop->platform_fee_terms_version) === trim((string) $config['terms_version'])
                    && $shop->platform_fee_terms_accepted_at !== null,
                'accepted_at' => $shop->platform_fee_terms_accepted_at?->toISOString(),
            ],
        ]);
    }
}
