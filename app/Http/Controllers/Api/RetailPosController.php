<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RetailPosPaymentService;
use App\Services\RetailPosRefundService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RetailPosController extends Controller
{
    public function checkout(Request $request, RetailPosPaymentService $service)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'customer_type' => ['required', 'string', 'in:registered,walk_in'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'walk_in_email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
            'items.*.size' => ['nullable', 'string', 'max:50'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.image' => ['nullable', 'string', 'max:2048'],
            'payment_lines' => ['required', 'array', 'min:1'],
            'payment_lines.*.tender_type' => ['required', 'string', 'in:cash,paymongo_card,paymongo_wallet'],
            'payment_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_lines.*.provider_reference' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ((array) $validated['payment_lines'] as $index => $line) {
            $isNonCash = in_array($line['tender_type'] ?? '', ['paymongo_card', 'paymongo_wallet'], true);
            $reference = trim((string) ($line['provider_reference'] ?? ''));

            if ($isNonCash && $reference === '') {
                throw ValidationException::withMessages([
                    "payment_lines.{$index}.provider_reference" => ['Reference is required for non-cash payments.'],
                ]);
            }
        }

        if ((string) ($validated['customer_type'] ?? '') === 'walk_in' && trim((string) ($validated['walk_in_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'walk_in_name' => ['Walk-in customer name is required.'],
            ]);
        }

        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $transaction = $service->checkout($shopOwnerId, $validated, $this->resolveActorAuditUserId());

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (int) $transaction->id,
                'transaction_no' => (string) $transaction->transaction_no,
                'module_type' => (string) $transaction->module_type,
                'module_reference_id' => (int) $transaction->module_reference_id,
                'status' => (string) $transaction->status,
            ],
            'meta' => [
                'idempotency_replay' => (bool) $transaction->getAttribute('idempotency_replay'),
            ],
        ], 201);
    }

    public function listTransactions(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $perPage = max(1, min((int) $request->query('per_page', 50), 200));

        $rows = PosTransaction::query()
            ->where('module_type', 'retail')
            ->where('shop_owner_id', $shopOwnerId)
            ->with(['paymentLines', 'receipt', 'refunds' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function showReceipt(PosTransaction $transaction)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        abort_if((string) $transaction->module_type !== 'retail', 404);
        abort_if((int) $transaction->shop_owner_id !== $shopOwnerId, 404);

        return response()->json([
            'success' => true,
            'data' => $transaction->load(['paymentLines', 'receipt']),
        ]);
    }

    public function requestRefund(Request $request, RetailPosRefundService $service)
    {
        $validated = $request->validate([
            'source_transaction_id' => ['required', 'integer', 'exists:pos_transactions,id'],
            'request_type' => ['required', 'string', 'in:full,partial'],
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason_code' => ['required', 'string', 'max:100'],
            'reason_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $source = PosTransaction::query()->findOrFail((int) $validated['source_transaction_id']);
        if ((string) $source->module_type !== 'retail') {
            return response()->json([
                'success' => false,
                'message' => 'Only retail transactions can be refunded from this endpoint.',
            ], 422);
        }

        $actor = $this->resolveActor();
        $actorId = $this->resolveActorId();
        if (!$actor || $actorId <= 0) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $actorShopOwnerId = $this->resolveActorShopOwnerId($actor);
        $isShopActor = $actorShopOwnerId > 0 && $actorShopOwnerId === (int) $source->shop_owner_id;
        $isCustomerOwner = Auth::guard('user')->check() && (int) ($source->customer_id ?? 0) === $actorId;

        if (!$isShopActor && !$isCustomerOwner) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to request a refund for this transaction.',
            ], 403);
        }

        $refund = $service->requestRefund($source, $validated, $this->resolveActorAuditUserId());

        return response()->json([
            'success' => true,
            'refund_id' => (int) $refund->id,
            'data' => $refund,
        ]);
    }

    public function approveRefund(Request $request, PosRefund $refund, RetailPosRefundService $service)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        if ((int) $refund->shop_owner_id !== $shopOwnerId || (string) $refund->module_type !== 'retail') {
            return response()->json([
                'success' => false,
                'message' => 'Refund not found in this retail scope.',
            ], 404);
        }

        $validated = $request->validate([
            'approved_amount' => ['nullable', 'numeric', 'min:0.01'],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->approve(
            refund: $refund,
            actorId: $this->resolveActorAuditUserId(),
            approvedAmount: isset($validated['approved_amount']) ? (float) $validated['approved_amount'] : null,
            approvalNote: $validated['approval_note'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function executeRefund(Request $request, PosRefund $refund, RetailPosRefundService $service)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        if ((int) $refund->shop_owner_id !== $shopOwnerId || (string) $refund->module_type !== 'retail') {
            return response()->json([
                'success' => false,
                'message' => 'Refund not found in this retail scope.',
            ], 404);
        }

        $validated = $request->validate([
            'execution_mode' => ['nullable', 'string', 'in:manual,gateway'],
            'execution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $service->execute(
            refund: $refund,
            actorId: $this->resolveActorAuditUserId(),
            executionMode: (string) ($validated['execution_mode'] ?? 'manual'),
            executionNote: $validated['execution_note'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    private function resolveActor(): ?object
    {
        return Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();
    }

    private function resolveActorId(): int
    {
        return (int) (Auth::guard('user')->id() ?? Auth::guard('shop_owner')->id() ?? 0);
    }

    private function resolveActorShopOwnerId(?object $actor = null): int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->user()?->shop_owner_id ?? 0);
        }

        return (int) ($actor?->shop_owner_id ?? 0);
    }

    private function resolveActorAuditUserId(): int
    {
        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->id() ?? 0);
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return 0;
        }

        $shopOwnerId = (int) ($shopOwner->id ?? 0);
        if ($shopOwnerId <= 0) {
            return 0;
        }

        $shopOwnerEmail = trim((string) ($shopOwner->email ?? ''));
        if ($shopOwnerEmail !== '') {
            $matchedByEmail = (int) (User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('email', $shopOwnerEmail)
                ->value('id') ?? 0);

            if ($matchedByEmail > 0) {
                return $matchedByEmail;
            }
        }

        return (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    private function assertRetailOrBoth(int $shopOwnerId): void
    {
        $businessType = $this->normalizeBusinessType((string) ShopOwner::query()->whereKey($shopOwnerId)->value('business_type'));

        if (in_array($businessType, ['retail', 'both'], true)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'BUSINESS_TYPE_FORBIDDEN_MODE',
            'message' => 'Retail POS is not available for this business type.',
        ], 403));
    }

    private function normalizeBusinessType(string $value): string
    {
        $normalized = strtolower(trim($value));

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        if ($normalized === 'retail') {
            return 'retail';
        }

        if ($normalized === 'repair') {
            return 'repair';
        }

        return '';
    }
}
