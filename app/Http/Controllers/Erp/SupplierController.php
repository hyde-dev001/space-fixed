<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierPaymentProfile;
use App\Models\SupplierAdjustment;
use App\Models\SupplierPaymentAttempt;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseSettlement;
use App\Http\Requests\StoreSupplierPaymentProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\Erp\ErpActorContext;
use App\Models\PurchaseOrder;

class SupplierController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of suppliers
     */
    public function index(Request $request)
    {
        $context = request()->attributes->get('erp.actor_context');
        $ownerMode = $context instanceof ErpActorContext && $context->isOwnerMode();
        if (!$ownerMode) {
            $this->authorize('viewAny', Supplier::class);
        }

        $shopOwnerId = $ownerMode
            ? (int) $context->tenantOwner()->getKey()
            : $request->user()?->shop_owner_id;
        if (!$shopOwnerId) {
            return response()->json(['message' => 'Shop context is missing for this account.'], 403);
        }
        $showArchived = $request->boolean('archived');
        
        $suppliers = Supplier::where('shop_owner_id', $shopOwnerId)
            ->when($showArchived, function ($query) {
                $query->onlyTrashed();
            })
            ->withCount([
                'purchaseOrders as purchase_order_count' => function ($query) use ($shopOwnerId) {
                    $query->where('shop_owner_id', $shopOwnerId);
                },
            ])
            ->withMax([
                'purchaseOrders as last_order_date' => function ($query) use ($shopOwnerId) {
                    $query->where('shop_owner_id', $shopOwnerId);
                },
            ], 'ordered_date')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
        
        return response()->json($suppliers);
    }

    /**
     * Store a newly created supplier
     */
    public function store(Request $request)
    {
        $this->authorize('create', Supplier::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|digits_between:1,11',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => ['nullable', 'string', Rule::in(PurchaseOrder::supportedPaymentTerms())],
            'lead_time_days' => 'nullable|integer|min:0',
            'products_supplied' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);
        
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::create(array_merge($validated, [
            'shop_owner_id' => $shopOwnerId,
            'is_active' => true
        ]));
        
        return response()->json([
            'message' => 'Supplier created successfully',
            'data' => $supplier
        ], 201);
    }

    /**
     * Display the specified supplier
     */
    public function show(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::with(['purchaseOrders' => function ($query) {
                $query->latest()->limit(10);
            }])
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        $this->authorize('view', $supplier);
        
        return response()->json($supplier);
    }

    /**
     * Update the specified supplier
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::where('shop_owner_id', $request->user()->shop_owner_id)
            ->findOrFail($id);
        $this->authorize('update', $supplier);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|digits_between:1,11',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'payment_terms' => ['nullable', 'string', Rule::in(PurchaseOrder::supportedPaymentTerms())],
            'lead_time_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'products_supplied' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);
        
        $supplier->update($validated);
        
        return response()->json([
            'message' => 'Supplier updated successfully',
            'data' => $supplier
        ]);
    }

    /**
     * Archive the specified supplier (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;
        
        $supplier = Supplier::where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        $this->authorize('delete', $supplier);
        
        // Check if supplier has active orders
        $activeOrders = $supplier->purchaseOrders()
            ->active()
            ->count();
        
        if ($activeOrders > 0) {
            return response()->json([
                'message' => 'Cannot archive supplier with active orders',
                'active_orders' => $activeOrders
            ], 422);
        }

        $hasUnresolvedAdjustment = SupplierAdjustment::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', '<>', SupplierAdjustment::STATUS_RESOLVED)
            ->whereHas('receiptItem.receipt.purchaseOrder', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->exists();
        if ($hasUnresolvedAdjustment) {
            return response()->json([
                'message' => 'Cannot archive supplier with unresolved adjustments',
            ], 422);
        }

        $hasUnpaidReleasedExpense = Expense::query()
            ->where('shop_id', $shopOwnerId)
            ->where('status', 'posted')
            ->whereHas('procurementReceipt.purchaseOrder', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->get(['id', 'amount'])
            ->contains(fn (Expense $expense): bool => $this->moneyCents($expense->amount)
                > $this->moneyCents(ExpenseSettlement::validSettledAmountForExpense((int) $expense->id)));
        if ($hasUnpaidReleasedExpense) {
            return response()->json([
                'message' => 'Cannot archive supplier with unpaid released expenses',
            ], 422);
        }

        $hasActivePayment = SupplierPaymentAttempt::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', [
                SupplierPaymentAttempt::STATUS_INITIATING,
                SupplierPaymentAttempt::STATUS_AWAITING_VERIFICATION,
            ])
            ->exists();
        if ($hasActivePayment) {
            return response()->json([
                'message' => 'Cannot archive supplier with an active payment attempt',
            ], 422);
        }
        
        $supplier->delete();
        
        return response()->json([
            'message' => 'Supplier archived successfully',
            'data' => $supplier
        ]);
    }

    private function moneyCents(mixed $amount): int
    {
        $text = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Restore an archived supplier
     */
    public function restore(Request $request, $id)
    {
        $shopOwnerId = $request->user()->shop_owner_id;

        $supplier = Supplier::onlyTrashed()
            ->where('shop_owner_id', $shopOwnerId)
            ->findOrFail($id);

        $this->authorize('restore', $supplier);

        $supplier->restore();

        return response()->json([
            'message' => 'Supplier restored successfully',
            'data' => $supplier->fresh()
        ]);
    }

    public function showPaymentProfile(Request $request, int $id)
    {
        $supplier = Supplier::query()
            ->where('shop_owner_id', $request->user()->shop_owner_id)
            ->findOrFail($id);

        $this->authorize('view', $supplier);

        return response()->json([
            'data' => $supplier->paymentProfile?->toMaskedArray(),
        ]);
    }

    public function upsertPaymentProfile(StoreSupplierPaymentProfileRequest $request, int $id)
    {
        $supplier = Supplier::query()
            ->where('shop_owner_id', $request->user()->shop_owner_id)
            ->findOrFail($id);

        $this->authorize('update', $supplier);

        $profile = DB::transaction(function () use ($request, $supplier): SupplierPaymentProfile {
            $lockedSupplier = Supplier::query()
                ->where('shop_owner_id', $supplier->shop_owner_id)
                ->lockForUpdate()
                ->findOrFail($supplier->id);

            $profile = SupplierPaymentProfile::query()
                ->where('shop_owner_id', $lockedSupplier->shop_owner_id)
                ->where('supplier_id', $lockedSupplier->id)
                ->lockForUpdate()
                ->first();
            $data = $request->validated();
            $accountNumber = filled($data['account_number'] ?? null)
                ? $data['account_number']
                : $profile?->account_number;

            if ($accountNumber === null) {
                abort(422, 'An account number is required for a new payment profile.');
            }

            $destination = [
                'destination_type' => $data['destination_type'],
                'bank_name' => $data['bank_name'],
                'bank_code' => $data['bank_code'],
                'account_name' => $data['account_name'],
                'account_number' => $accountNumber,
            ];
            $changed = ! $profile || collect($destination)->some(
                fn ($value, $key): bool => (string) $profile->getAttribute($key) !== (string) $value
            );

            if (! $profile) {
                $profile = new SupplierPaymentProfile([
                    'shop_owner_id' => $lockedSupplier->shop_owner_id,
                    'supplier_id' => $lockedSupplier->id,
                ]);
            }

            $profile->fill($destination);
            if ($changed) {
                $profile->status = SupplierPaymentProfile::STATUS_UNVERIFIED;
                $profile->verified_by = null;
                $profile->verified_at = null;
            }
            $profile->save();

            return $profile->fresh();
        }, 3);

        return response()->json([
            'message' => 'Supplier payment profile saved successfully.',
            'data' => $profile->toMaskedArray(),
        ]);
    }
}
