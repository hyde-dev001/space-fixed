<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosRefund;
use App\Models\PosTransaction;
use App\Models\RepairRequest;
use App\Services\RepairPosPaymentService;
use App\Services\RepairPosRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RepairPosController extends Controller
{
    public function checkout(Request $request, RepairPosPaymentService $service)
    {
        $validated = $request->validate([
            'repair_request_id' => ['required', 'integer', 'exists:repair_requests,id'],
            'due_type' => ['required', 'string', 'in:deposit,balance,full'],
            'customer_type' => ['required', 'string', 'in:registered,walk_in'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:255'],
            'walk_in_phone' => ['nullable', 'string', 'max:30'],
            'walk_in_email' => ['nullable', 'email', 'max:255'],
            'payment_lines' => ['required', 'array', 'min:1'],
            'payment_lines.*.tender_type' => ['required', 'string', 'in:cash,paymongo_card,paymongo_wallet'],
            'payment_lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payment_lines.*.provider_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $repair = RepairRequest::findOrFail((int) $validated['repair_request_id']);
        $actorId = (int) (Auth::guard('user')->id() ?? 0);

        $transaction = $service->checkout($repair, $validated, $actorId);

        return response()->json([
            'success' => true,
            'transaction_id' => $transaction->id,
            'transaction_no' => $transaction->transaction_no,
        ]);
    }

    public function showTransaction(PosTransaction $transaction)
    {
        return response()->json([
            'success' => true,
            'data' => $transaction->load(['paymentLines', 'receipt']),
        ]);
    }

    public function requestRefund(Request $request, RepairPosRefundService $service)
    {
        $validated = $request->validate([
            'source_transaction_id' => ['required', 'integer', 'exists:pos_transactions,id'],
            'request_type' => ['required', 'string', 'in:full,partial'],
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'reason_code' => ['required', 'string', 'max:100'],
            'reason_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $source = PosTransaction::findOrFail((int) $validated['source_transaction_id']);
        $actorId = (int) (Auth::guard('user')->id() ?? 0);

        $refund = $service->requestRefund($source, $validated, $actorId);

        return response()->json([
            'success' => true,
            'refund_id' => $refund->id,
        ]);
    }

    public function listRefundQueue(Request $request)
    {
        $user = Auth::guard('user')->user();
        $shopOwnerId = (int) ($user?->shop_owner_id ?? 0);

        $refunds = PosRefund::query()
            ->where('module_type', 'repair')
            ->whereIn('status', ['requested', 'approved', 'processing'])
            ->when($shopOwnerId > 0, fn ($query) => $query->where('shop_owner_id', $shopOwnerId))
            ->with([
                'sourceTransaction:id,transaction_no,module_reference_id,paid_amount,paid_at',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $refunds,
        ]);
    }

    public function showReceipt(PosTransaction $transaction)
    {
        $transaction->load(['receipt', 'paymentLines']);

        if (!$transaction->receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found for this transaction.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->receipt,
        ]);
    }
}
