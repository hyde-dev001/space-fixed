<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\Finance\Account;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * List invoices with filtering
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            if (!$shopOwnerId) {
                return response()->json(['error' => 'No shop association found'], 403);
            }
            
            $q = Invoice::where('shop_id', $shopOwnerId);

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
                $query->select('id', 'order_number', 'customer_id', 'status', 'total_amount', 'created_at');
            }])
            ->orderBy('date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($invoices);
        } catch (\Exception $e) {
            Log::error('Error fetching invoices: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load invoices', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get single invoice with items
     */
    public function show($id)
    {
        $user = Auth::guard('user')->user();
        $shopOwnerId = $user->hasRole('Shop Owner') ? $user->id : $user->shop_owner_id;
        
        // Include job order data when fetching single invoice
        $invoice = Invoice::where('shop_id', $shopOwnerId)
            ->with([
                'items', 
                'journalEntry.lines',
                'jobOrder' => function($query) {
                    $query->select('id', 'order_number', 'customer_id', 'status', 'total_amount', 'created_at', 'updated_at');
                }
            ])
            ->findOrFail($id);
        return response()->json($invoice);
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
            'items.*.account_id' => 'required|integer|exists:finance_accounts,id',
        ]);

        try {
            $user = Auth::guard('user')->user();
            $shopOwnerId = $user->hasRole('Shop Owner') ? $user->id : $user->shop_owner_id;
            
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
                    'created_by' => $user->id,
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
                    'account_id' => $item['account_id'],
                ]);
            }

            // Audit log
            $actorUserId = Auth::guard('user')->id() ?? Auth::id();
            $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'create_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => $invoice->toArray(),
            ]);

            DB::commit();

            return response()->json($invoice->load('items'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to create invoice', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update invoice (draft only)
     */
    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

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
            'items.*.account_id' => 'required_with:items|integer|exists:finance_accounts,id',
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
                        'account_id' => $item['account_id'],
                    ]);
                }

                $invoice->update(['total' => $total, 'tax_amount' => $taxAmount]);
            }

            // Audit log
            $actorUserId = Auth::guard('user')->id() ?? Auth::id();
            $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
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
            Log::error('Invoice update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to update invoice', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Post invoice to ledger (creates journal entry and transitions status)
     */
    public function post(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->status === 'posted') {
            return response()->json(['message' => 'Invoice already posted'], 422);
        }

        try {
            DB::beginTransaction();

            // Create journal entry if not exists
            if (!$invoice->journal_entry_id) {
                $invoice->createJournalEntry();
            }

            // Post the journal entry
            $invoice->postToLedger();

            // Audit log
            $actorUserId = Auth::guard('user')->id() ?? Auth::id();
            $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'post_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => ['status' => 'posted'],
            ]);

            DB::commit();

            return response()->json($invoice->load('items', 'journalEntry.lines'));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice posting failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to post invoice', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete invoice (draft only)
     */
    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted'], 422);
        }

        // Audit log
        $actorUserId = Auth::guard('user')->id() ?? Auth::id();
        $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => 'delete_invoice',
            'target_type' => 'invoice',
            'target_id' => $invoice->id,
        ]);

        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted']);
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
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $shopOwnerId = $user->hasRole('Shop Owner') ? $user->id : $user->shop_owner_id;
            
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
            
            // Calculate total from order (orders table has total_amount column)
            $total = isset($job->total_amount) ? floatval($job->total_amount) : 0;
            
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
                'tax_amount' => 0,
                'status' => 'draft',
                'shop_id' => $shopOwnerId,
                'notes' => 'Auto-generated from Job Order #' . $job->order_number,
                'meta' => [
                    'created_by' => $user->id,
                    'source' => 'job_order',
                    'job_order_id' => $job->id
                ]
            ]);
            
            // Create invoice items (simplified - one line item for the order)
            $description = 'Order #' . $job->order_number . 
                          (isset($job->status) ? ' - ' . ucfirst($job->status) : '');
            
            // Get revenue account (first revenue account, or null if none exists)
            $revenueAccount = Account::where('shop_id', $shopOwnerId)
                ->where('type', 'LIKE', '%revenue%')
                ->first();
            
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $total,
                'tax_rate' => 0,
                'amount' => $total,
                'account_id' => $revenueAccount ? $revenueAccount->id : null
            ]);
            
            // Note: orders table doesn't have invoice_generated or invoice_id columns
            // If you want to track this, add migration to add these columns
            // For now, skip the update
            
            // Audit log
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $user->id,
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
            Log::error('Failed to create invoice from job: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Failed to create invoice',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send invoice (change status from draft to sent)
     */
    public function send($id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
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
                'actor_user_id' => $user->id,
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
            Log::error('Failed to send invoice: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to send invoice',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'payment_date' => 'required|date',
                'payment_method' => 'required|string|in:cash,bank_transfer,check,gcash,maya,paypal,other',
            ]);

            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;
            
            $invoice = Invoice::where('shop_id', $shopOwnerId)
                ->where('id', $id)
                ->firstOrFail();

            if (!in_array($invoice->status, ['sent', 'overdue'])) {
                return response()->json(['error' => 'Only sent or overdue invoices can be marked as paid'], 422);
            }

            $invoice->update([
                'status' => 'paid',
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
            ]);

            // Audit log
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $user->id,
                'action' => 'mark_invoice_paid',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'reference' => $invoice->reference,
                    'customer' => $invoice->customer_name,
                    'amount' => $invoice->total,
                    'payment_date' => $validated['payment_date'],
                    'payment_method' => $validated['payment_method']
                ]
            ]);

            return response()->json([
                'message' => 'Invoice marked as paid successfully',
                'invoice' => $invoice->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark invoice as paid: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to mark invoice as paid',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
