<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\AuditLog;
use App\Services\NotificationService;
use App\Services\Finance\InvoicePaymentService;
use App\Support\Finance\FinanceDomainException;
use App\Support\Finance\FinanceShopContext;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    protected NotificationService $notificationService;
    protected FinanceShopContext $shopContext;

    public function __construct(
        NotificationService $notificationService,
        FinanceShopContext $shopContext,
        InvoicePaymentService $paymentService,
    )
    {
        $this->notificationService = $notificationService;
        $this->shopContext = $shopContext;
        $this->paymentService = $paymentService;
    }
    protected InvoicePaymentService $paymentService;
    /**
     * List invoices with filtering
     */
    public function index(Request $request)
    {
        try {
            $shopOwnerId = $this->shopOwnerId();

            if (!$shopOwnerId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            $q = Invoice::where('shop_id', $shopOwnerId);

            if ($request->boolean('archived')) {
                $q->onlyTrashed();
            }

            if ($request->filled('status')) {
                $q->where('status', $request->status);
            }
            
            // Filter by job order status
            if ($request->filled('job_status')) {
                $q->whereHas('jobOrder', function($query) use ($request) {
                    $query->where('status', $request->job_status);
                });
            }
            
            // Filter for invoices with or without job links
            if ($request->filled('has_job')) {
                if ($request->has_job === 'true' || $request->has_job === true) {
                    $q->whereNotNull('job_order_id');
                } else {
                    $q->whereNull('job_order_id');
                }
            }

        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(function ($w) use ($search) {
                $w->where('reference', 'like', "%$search%")
                    ->orWhere('customer_name', 'like', "%$search%")
                    ->orWhere('customer_email', 'like', "%$search%");
            });
        }

        if ($request->filled('date_from')) {
            $q->where('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $q->where('date', '<=', $request->date_to);
        }

        // Include job order data in results
        $invoices = $q->with(['items', 'jobOrder' => function($query) {
            $query->select('id', 'order_number', 'customer_id', 'status', 'payment_status', 'total_amount', 'shipping_fee', 'vat_amount', 'vat_rate', 'created_at');
            }])
            ->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));

        $invoices->setCollection($invoices->getCollection()->map(function (Invoice $invoice) use ($shopOwnerId) {
            $invoice->setAttribute('payment_state', $this->paymentService->state($invoice, (int) $shopOwnerId));
            return $invoice;
        }));

        return response()->json($invoices);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'invoice.index');
        }
    }

    /**
     * Get single invoice with items
     */
    public function show($id)
    {
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Include job order data when fetching single invoice
        $invoice = Invoice::withTrashed()
            ->where('shop_id', $shopOwnerId)
            ->with([
                'items', 
                'journalEntry.lines',
                'jobOrder' => function($query) {
                    $query->select('id', 'order_number', 'customer_id', 'status', 'payment_status', 'total_amount', 'shipping_fee', 'vat_amount', 'vat_rate', 'created_at', 'updated_at');
                }
            ])
            ->findOrFail($id);
        $payload = $invoice->toArray();
        $payload['payment_state'] = $this->paymentService->state($invoice, (int) $shopOwnerId);

        return response()->json($payload);
    }

    /**
     * Create new invoice (draft)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => 'required|string|unique:finance_invoices,reference',
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email',
            'date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $shopOwnerId = $this->shopOwnerId();
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            DB::beginTransaction();

            // Calculate totals
            $total = 0;
            $taxAmount = 0;

            foreach ($data['items'] as $item) {
                $itemAmount = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemAmount * ($item['tax_rate'] ?? 0) / 100;
                $total += $itemAmount + $itemTax;
                $taxAmount += $itemTax;
            }

            // Create invoice
            $invoice = Invoice::create([
                'reference' => $data['reference'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'] ?? null,
                'date' => $data['date'],
                'due_date' => $data['due_date'] ?? null,
                'total' => $total,
                'tax_amount' => $taxAmount,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'shop_id' => $shopOwnerId,
                'meta' => [
                    'created_by' => $this->actorUserId(),
                ],
            ]);

            // Create line items
            foreach ($data['items'] as $item) {
                $itemAmount = $item['quantity'] * $item['unit_price'];
                $itemTax = $itemAmount * ($item['tax_rate'] ?? 0) / 100;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'amount' => $itemAmount + $itemTax,
                    'account_id' => null,
                ]);
            }

            // Audit log
            $actorUserId = $this->actorUserId();
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'create_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => $invoice->toArray(),
            ]);

            DB::commit();

            // Live notification to all Finance users in this shop
            try {
                $this->notificationService->notifyInvoiceCreatedToFinance($shopOwnerId, [
                    'invoice_id' => $invoice->id,
                    'reference'  => $invoice->reference,
                    'total'      => number_format($invoice->total, 2),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send live invoice notification', ['error' => $e->getMessage()]);
            }

            return response()->json($invoice->load('items'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'invoice.create', 500, ['shop_id' => $shopOwnerId ?? null]);
        }
    }

    /**
     * Update invoice (draft only)
     */
    public function update(Request $request, $id)
    {
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return response()->json(['error' => 'No shop association found'], 403);
        }

        $invoice = Invoice::where('shop_id', $shopOwnerId)->findOrFail($id);

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be edited'], 422);
        }

        $data = $request->validate([
            'customer_name' => 'sometimes|string|max:255',
            'customer_email' => 'sometimes|nullable|email',
            'due_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Update header
            $invoice->update(array_filter($data, fn($k) => !in_array($k, ['items']), ARRAY_FILTER_USE_KEY));

            // Update items if provided
            if (!empty($data['items'])) {
                $invoice->items()->delete();

                $total = 0;
                $taxAmount = 0;

                foreach ($data['items'] as $item) {
                    $itemAmount = $item['quantity'] * $item['unit_price'];
                    $itemTax = $itemAmount * ($item['tax_rate'] ?? 0) / 100;
                    $total += $itemAmount + $itemTax;
                    $taxAmount += $itemTax;

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'tax_rate' => $item['tax_rate'] ?? 0,
                        'amount' => $itemAmount + $itemTax,
                        'account_id' => null,
                    ]);
                }

                $invoice->update(['total' => $total, 'tax_amount' => $taxAmount]);
            }

            // Audit log
            $actorUserId = $this->actorUserId();
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'update_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => $data,
            ]);

            DB::commit();

            return response()->json($invoice->load('items'));
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'invoice.update', 500, ['record_id' => $id]);
        }
    }

    /**
     * Post invoice to ledger (creates journal entry and transitions status)
     */
    public function post(Request $request, $id)
    {
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return response()->json(['error' => 'No shop association found'], 403);
        }

        $invoice = Invoice::where('shop_id', $shopOwnerId)->findOrFail($id);

        if ($invoice->status === 'posted') {
            return response()->json(['message' => 'Invoice already posted'], 422);
        }

        try {
            DB::beginTransaction();

            // Lightweight posting: mark invoice as posted without account/journal dependency
            try {
                $invoice->update(['status' => 'posted']);
            } catch (\Throwable $statusError) {
                // Backward-compat: some DBs may not include `posted` in enum yet
                $meta = is_array($invoice->meta) ? $invoice->meta : [];
                $meta['ledger_posted'] = true;
                $meta['ledger_posted_at'] = now()->toDateTimeString();
                $meta['ledger_posted_by'] = $this->actorUserId();
                DB::table('finance_invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'meta' => json_encode($meta),
                        'updated_at' => now(),
                    ]);
                $invoice->refresh();
            }

            // Audit log
            $actorUserId = $this->actorUserId();
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'post_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => ['status' => 'posted'],
            ]);

            DB::commit();

            return response()->json($invoice->load('items'));
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'invoice.post', 500, ['record_id' => $id]);
        }
    }

    /**
     * Archive invoice (draft only)
     */
    public function destroy($id)
    {
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return response()->json(['error' => 'No shop association found'], 403);
        }

        $invoice = Invoice::where('shop_id', $shopOwnerId)->findOrFail($id);

        // Audit log
        $actorUserId = $this->actorUserId();
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => 'archive_invoice',
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);

        $invoice->delete();

        return response()->json(['message' => 'Invoice archived']);
    }

    /**
     * Restore archived invoice
     */
    public function restore($id)
    {
        $shopOwnerId = $this->shopOwnerId();
        if (! $shopOwnerId) {
            return response()->json(['error' => 'No shop association found'], 403);
        }

        $invoice = Invoice::withTrashed()
            ->where('shop_id', $shopOwnerId)
            ->whereNotNull('deleted_at')
            ->findOrFail($id);

        $invoice->restore();

        $actorUserId = $this->actorUserId();
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => 'restore_invoice',
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);

        return response()->json([
            'message' => 'Invoice restored',
            'invoice' => $invoice->fresh('items'),
        ]);
    }

    /**
     * Create invoice from job order
     */
    public function createFromJob(Request $request)
    {
        $validated = $request->validate([
            'job_id' => 'required|exists:orders,id',
            'auto_generate' => 'boolean'
        ]);
        
        try {
            $shopOwnerId = $this->shopOwnerId();
            if (! $shopOwnerId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            DB::beginTransaction();
            
            // Get job details with customer information
            $job = DB::table('orders')
                ->leftJoin('users as customers', 'orders.customer_id', '=', 'customers.id')
                ->where('orders.id', $validated['job_id'])
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->select([
                    'orders.*',
                    'customers.name as customer_name',
                    'customers.email as customer_email'
                ])
                ->first();
                
            if (!$job) {
                return response()->json(['error' => 'Job not found'], 404);
            }
            
            // Check if invoice already exists
            $existing = Invoice::where('job_order_id', $job->id)->first();
            if ($existing) {
                return response()->json([
                    'error' => 'Invoice already exists for this job',
                    'invoice' => $existing
                ], 400);
            }
            
            // Generate invoice reference
            $reference = 'INV-' . now()->format('YmdHis');
            
            $itemSubtotal = isset($job->total_amount) ? max(0.0, floatval($job->total_amount)) : 0.0;
            $shippingFee = isset($job->shipping_fee) ? max(0.0, floatval($job->shipping_fee)) : 0.0;
            $vatAmount = isset($job->vat_amount) && $job->vat_amount !== null
                ? max(0.0, floatval($job->vat_amount))
                : round($itemSubtotal * 0.12, 2);
            $total = $itemSubtotal + $shippingFee + $vatAmount;
            
            if ($total <= 0) {
                return response()->json(['error' => 'Job must have a valid total amount'], 400);
            }
            
            // Create invoice
            $invoice = Invoice::create([
                'reference' => $reference,
                'job_order_id' => $job->id,
                'job_reference' => $job->order_number,
                'customer_name' => $job->customer_name ?? 'Unknown Customer',
                'customer_email' => $job->customer_email ?? null,
                'date' => now(),
                'due_date' => now()->addDays(30),
                'total' => $total,
                'tax_amount' => $vatAmount,
                'status' => 'draft',
                'shop_id' => $shopOwnerId,
                'notes' => 'Auto-generated from Job Order #' . $job->order_number,
                'meta' => [
                    'created_by' => $this->actorUserId(),
                    'source' => 'job_order',
                    'job_order_id' => $job->id,
                    'subtotal_amount' => $itemSubtotal,
                    'shipping_fee' => $shippingFee,
                    'vat_amount' => $vatAmount,
                    'grand_total' => $total,
                ]
            ]);
            
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Order #' . $job->order_number . (isset($job->status) ? ' - ' . ucfirst($job->status) : ''),
                'quantity' => 1,
                'unit_price' => $itemSubtotal,
                'tax_rate' => $itemSubtotal > 0 && $vatAmount > 0 ? round(($vatAmount / $itemSubtotal) * 100, 2) : 0,
                'amount' => $itemSubtotal + $vatAmount,
                'account_id' => null,
            ]);

            if ($shippingFee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Shipping Fee',
                    'quantity' => 1,
                    'unit_price' => $shippingFee,
                    'tax_rate' => 0,
                    'amount' => $shippingFee,
                    'account_id' => null,
                ]);
            }
            
            // Note: orders table doesn't have invoice_generated or invoice_id columns
            // If you want to track this, add migration to add these columns
            // For now, skip the update
            
            // Audit log
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $this->actorUserId(),
                'action' => 'create_invoice_from_job',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'job_id' => $job->id,
                    'order_number' => $job->order_number,
                    'invoice_reference' => $reference,
                    'auto_generated' => $validated['auto_generate'] ?? false
                ]
            ]);
            
            DB::commit();
            
            return response()->json($invoice->load('items'), 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return FinanceErrorResponse::json($e, 'invoice.create_from_job');
        }
    }

    /**
     * Send invoice (change status from draft to sent)
     */
    public function send($id)
    {
        try {
            $shopOwnerId = $this->shopOwnerId();

            if (!$shopOwnerId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $invoice = Invoice::where('shop_id', $shopOwnerId)
                ->where('id', $id)
                ->firstOrFail();

            if ($invoice->status !== 'draft') {
                return response()->json(['error' => 'Only draft invoices can be sent'], 422);
            }

            $invoice->update(['status' => 'sent']);

            // Audit log
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $this->actorUserId(),
                'action' => 'send_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'reference' => $invoice->reference,
                    'customer' => $invoice->customer_name,
                    'amount' => $invoice->total
                ]
            ]);

            return response()->json([
                'message' => 'Invoice sent successfully',
                'invoice' => $invoice->fresh()
            ]);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'invoice.send', 500, ['record_id' => $id]);
        }
    }

    /**
     * Void invoice (cancel before payment)
     */
    public function void($id)
    {
        try {
            $shopOwnerId = $this->shopOwnerId();

            if (!$shopOwnerId) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $invoice = Invoice::where('shop_id', $shopOwnerId)
                ->where('id', $id)
                ->firstOrFail();

            if (in_array($invoice->status, ['paid', 'cancelled'])) {
                return response()->json(['error' => 'Paid/cancelled invoices cannot be voided'], 422);
            }

            $previousStatus = $invoice->status;
            $invoice->update(['status' => 'cancelled']);

            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $this->actorUserId(),
                'action' => 'void_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'reference' => $invoice->reference,
                    'previous_status' => $previousStatus,
                ],
            ]);

            return response()->json([
                'message' => 'Invoice voided successfully',
                'invoice' => $invoice->fresh(),
            ]);
        } catch (\Exception $e) {
            return FinanceErrorResponse::json($e, 'invoice.void', 500, ['record_id' => $id]);
        }
    }

    public function recordPayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', Rule::in(InvoicePaymentService::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:191'],
            'received_at' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $user = Auth::guard('user')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'FORBIDDEN'], 401);
        }

        $shopOwnerId = $this->shopContext->id($request);
        $invoice = Invoice::query()->where('shop_id', $shopOwnerId)->findOrFail($id);

        try {
            $result = $this->paymentService->record($invoice, $user, $validated);

            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $user->id,
                'action' => 'record_invoice_payment',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'payment_id' => $result['payment']->id,
                    'amount' => $result['payment']->amount,
                    'replayed' => $result['replayed'],
                ],
            ]);

            return response()->json([
                'message' => $result['replayed'] ? 'Payment request already recorded.' : 'Payment recorded successfully.',
                'payment' => $result['payment'],
                'invoice' => $result['invoice'],
                'replayed' => $result['replayed'],
            ], $result['replayed'] ? 200 : 201);
        } catch (FinanceDomainException $exception) {
            return FinanceErrorResponse::json($exception, 'invoice.payment.create', $exception->httpStatus, [
                'record_id' => $id,
                'shop_id' => $shopOwnerId,
            ]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'invoice.payment.create', 500, [
                'record_id' => $id,
                'shop_id' => $shopOwnerId,
            ]);
        }
    }

    public function listPayments(Request $request, $id)
    {
        $shopOwnerId = $this->shopContext->id($request);
        $invoice = Invoice::query()->where('shop_id', $shopOwnerId)->findOrFail($id);

        return response()->json($this->paymentService->state($invoice, $shopOwnerId));
    }

    public function reversePayment(Request $request, $id, $paymentId)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $user = Auth::guard('user')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'FORBIDDEN'], 401);
        }

        $shopOwnerId = $this->shopContext->id($request);
        $invoice = Invoice::query()->where('shop_id', $shopOwnerId)->findOrFail($id);
        $payment = $invoice->payments()->where('shop_owner_id', $shopOwnerId)->findOrFail($paymentId);

        try {
            $reversal = $this->paymentService->reverse($payment, $user, $validated['reason']);

            return response()->json([
                'message' => 'Payment reversal recorded successfully.',
                'payment' => $reversal,
                'invoice' => $this->paymentService->state($invoice->fresh(), $shopOwnerId),
            ], 201);
        } catch (FinanceDomainException $exception) {
            return FinanceErrorResponse::json($exception, 'invoice.payment.reverse', $exception->httpStatus, [
                'record_id' => $paymentId,
                'shop_id' => $shopOwnerId,
            ]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'invoice.payment.reverse', 500, [
                'record_id' => $paymentId,
                'shop_id' => $shopOwnerId,
            ]);
        }
    }

    /** Compatibility endpoint retained during the route migration. */
    public function markAsPaid(Request $request, $id)
    {
        return response()->json([
            'message' => 'Use the record payment endpoint instead.',
            'code' => 'PAYMENT_ROUTE_MOVED',
        ], 410);
    }

    private function shopOwnerId(): ?int
    {
        return $this->shopContext->id(request());
    }

    private function actorUserId(): ?int
    {
        return Auth::guard('user')->id();
    }
}
