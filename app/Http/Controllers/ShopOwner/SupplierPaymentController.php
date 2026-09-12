<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopOwner\RejectSupplierPaymentRequest;
use App\Models\ShopOwner;
use App\Models\SupplierPaymentAttempt;
use App\Services\Finance\SupplierPaymentService;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Http\Request;

final class SupplierPaymentController extends Controller
{
    public function __construct(private readonly SupplierPaymentService $supplierPaymentService) {}

    public function index(Request $request)
    {
        $shopOwner = $request->user('shop_owner');
        abort_unless($shopOwner instanceof ShopOwner, 401);

        $attempts = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->where('status', SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION)
            ->latest('id')
            ->get()
            ->map(fn (SupplierPaymentAttempt $attempt): array => $this->supplierPaymentService->present($attempt))
            ->values()
            ->all();

        return response()->json(['data' => $attempts]);
    }

    public function show(Request $request, int $attemptId)
    {
        $shopOwner = $request->user('shop_owner');
        abort_unless($shopOwner instanceof ShopOwner, 401);
        $attempt = $this->attemptForOwner($shopOwner, $attemptId);

        return response()->json(['data' => $this->supplierPaymentService->present($attempt)]);
    }

    public function confirm(Request $request, int $attemptId)
    {
        $shopOwner = $request->user('shop_owner');
        abort_unless($shopOwner instanceof ShopOwner, 401);
        $attempt = $this->attemptForOwner($shopOwner, $attemptId);

        try {
            $confirmed = $this->supplierPaymentService->confirm($attempt, $shopOwner);

            return response()->json(['data' => $this->supplierPaymentService->present($confirmed)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.confirm', 500, [
                'shop_id' => $shopOwner->id,
                'record_id' => $attemptId,
            ]);
        }
    }

    public function reject(RejectSupplierPaymentRequest $request, int $attemptId)
    {
        $shopOwner = $request->user('shop_owner');
        abort_unless($shopOwner instanceof ShopOwner, 401);
        $attempt = $this->attemptForOwner($shopOwner, $attemptId);

        try {
            $rejected = $this->supplierPaymentService->reject(
                $attempt,
                $shopOwner,
                (string) $request->validated('reason'),
            );

            return response()->json(['data' => $this->supplierPaymentService->present($rejected)]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'supplier_payment.reject', 500, [
                'shop_id' => $shopOwner->id,
                'record_id' => $attemptId,
            ]);
        }
    }

    public function proof(Request $request, int $attemptId, int $mediaId)
    {
        $shopOwner = $request->user('shop_owner');
        abort_unless($shopOwner instanceof ShopOwner, 401);
        $attempt = $this->attemptForOwner($shopOwner, $attemptId);
        $media = $attempt->getMedia('payment_proof')->firstWhere('id', $mediaId);
        abort_unless($media, 404);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function attemptForOwner(ShopOwner $shopOwner, int $attemptId): SupplierPaymentAttempt
    {
        return SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->findOrFail($attemptId);
    }
}
