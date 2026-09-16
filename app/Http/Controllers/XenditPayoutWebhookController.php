<?php

namespace App\Http\Controllers;

use App\Models\ShopPaymentIntegration;
use App\Models\SupplierPaymentAttempt;
use App\Services\Finance\SupplierPaymentService;
use App\Support\Finance\FinanceDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class XenditPayoutWebhookController extends Controller
{
    public function __construct(private readonly SupplierPaymentService $supplierPaymentService) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $payoutId = trim((string) ($data['payout_id'] ?? ''));
        $referenceId = trim((string) ($data['reference_id'] ?? ''));

        if ($payoutId === '' && $referenceId === '') {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $attempt = SupplierPaymentAttempt::query()
            ->where('provider', ShopPaymentIntegration::PROVIDER_XENDIT)
            ->where(function ($query) use ($payoutId, $referenceId): void {
                if ($payoutId !== '') {
                    $query->where('provider_reference', $payoutId);
                }
                if ($referenceId !== '') {
                    $method = $payoutId !== '' ? 'orWhere' : 'where';
                    $query->{$method}('internal_reference', $referenceId);
                }
            })
            ->first();

        if (! $attempt) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $integration = ShopPaymentIntegration::query()
            ->forSupplierPayouts((int) $attempt->shop_owner_id)
            ->first();
        $expectedToken = trim((string) ($integration?->webhook_callback_token ?? ''));
        $providedToken = trim((string) $request->header('x-callback-token', ''));

        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json(['message' => 'Invalid Xendit callback token.'], 401);
        }

        try {
            $updated = $this->supplierPaymentService->handleXenditWebhook($attempt, $payload);

            return response()->json([
                'received' => true,
                'status' => (string) $updated->status,
            ]);
        } catch (FinanceDomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        } catch (\Throwable $exception) {
            Log::error('Xendit payout webhook processing failed.', [
                'attempt_id' => (int) $attempt->id,
                'shop_id' => (int) $attempt->shop_owner_id,
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'message' => 'The Xendit payout webhook could not be processed.',
                'code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }
}
