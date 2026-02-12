<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Finance\TaxRate;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxRateController extends Controller
{
    /**
     * Get all tax rates
     */
    public function index(Request $request)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        
        $query = TaxRate::forShop($shopId);

        // Filter by active status
        if ($request->filled('active_only')) {
            $query->active();
        }

        // Filter by effective dates
        if ($request->filled('effective_only')) {
            $query->effective();
        }

        // Filter by applies_to
        if ($request->filled('applies_to')) {
            $query->appliesTo($request->applies_to);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%")
                  ->orWhere('description', 'like', "%$search%");
            });
        }

        $taxRates = $query->orderBy('is_default', 'desc')
                          ->orderBy('name')
                          ->paginate($request->get('per_page', 15));

        return response()->json($taxRates);
    }

    /**
     * Get a single tax rate
     */
    public function show($id)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $taxRate = TaxRate::forShop($shopId)->findOrFail($id);
        
        return response()->json($taxRate);
    }

    /**
     * Create a new tax rate
     */
    public function store(Request $request)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;

        $data = $request->validate([
            'name' => 'required|string|max:191',
            'code' => 'required|string|max:50|unique:finance_tax_rates,code,NULL,id,shop_id,' . $shopId,
            'rate' => 'required|numeric|min:0|max:100',
            'type' => 'required|in:percentage,fixed',
            'fixed_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'applies_to' => 'required|in:all,expenses,invoices,journal_entries',
            'is_default' => 'nullable|boolean',
            'is_inclusive' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'region' => 'nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            // If this is set as default, unset other defaults for this shop
            if ($data['is_default'] ?? false) {
                TaxRate::forShop($shopId)
                    ->where('applies_to', $data['applies_to'])
                    ->update(['is_default' => false]);
            }

            $taxRate = TaxRate::create([
                ...$data,
                'shop_id' => $shopId,
            ]);

            $this->audit('create_tax_rate', $taxRate->id, $taxRate->toArray());

            DB::commit();
            return response()->json($taxRate, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tax rate creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to create tax rate', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a tax rate
     */
    public function update(Request $request, $id)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $taxRate = TaxRate::forShop($shopId)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:191',
            'code' => 'sometimes|string|max:50|unique:finance_tax_rates,code,' . $id . ',id,shop_id,' . $shopId,
            'rate' => 'sometimes|numeric|min:0|max:100',
            'type' => 'sometimes|in:percentage,fixed',
            'fixed_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'applies_to' => 'sometimes|in:all,expenses,invoices,journal_entries',
            'is_default' => 'nullable|boolean',
            'is_inclusive' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'region' => 'nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            // If setting as default, unset other defaults
            if (($data['is_default'] ?? false) && !$taxRate->is_default) {
                $appliesTo = $data['applies_to'] ?? $taxRate->applies_to;
                TaxRate::forShop($shopId)
                    ->where('applies_to', $appliesTo)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            $taxRate->update($data);
            $this->audit('update_tax_rate', $taxRate->id, $data);

            DB::commit();
            return response()->json($taxRate);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Tax rate update failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to update tax rate', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a tax rate
     */
    public function destroy($id)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $taxRate = TaxRate::forShop($shopId)->findOrFail($id);

        try {
            $this->audit('delete_tax_rate', $taxRate->id, ['name' => $taxRate->name]);
            $taxRate->delete();

            return response()->json(['message' => 'Tax rate deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Tax rate deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to delete tax rate', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate tax for a given amount
     */
    public function calculate(Request $request)
    {
        $data = $request->validate([
            'tax_rate_id' => 'required|integer|exists:finance_tax_rates,id',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $taxRate = TaxRate::forShop($shopId)->findOrFail($data['tax_rate_id']);

        if (!$taxRate->isEffective()) {
            return response()->json(['message' => 'This tax rate is not currently effective'], 422);
        }

        $subtotal = (float) $data['subtotal'];
        $taxAmount = $taxRate->calculateTax($subtotal);
        $total = $taxRate->calculateTotal($subtotal);

        return response()->json([
            'tax_rate' => $taxRate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'is_inclusive' => $taxRate->is_inclusive,
        ]);
    }

    /**
     * Get default tax rate for a transaction type
     */
    public function getDefault(Request $request)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $appliesTo = $request->get('applies_to', 'all');

        $taxRate = TaxRate::forShop($shopId)
            ->appliesTo($appliesTo)
            ->effective()
            ->default()
            ->first();

        if (!$taxRate) {
            // Fallback to any effective tax rate for this type
            $taxRate = TaxRate::forShop($shopId)
                ->appliesTo($appliesTo)
                ->effective()
                ->first();
        }

        return response()->json($taxRate);
    }

    /**
     * Get all effective tax rates for selection
     */
    public function effective(Request $request)
    {
        $shopId = auth()->user()?->shop_owner_id ?? 1;
        $appliesTo = $request->get('applies_to', 'all');

        $taxRates = TaxRate::forShop($shopId)
            ->appliesTo($appliesTo)
            ->effective()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($taxRates);
    }

    /**
     * Audit logging helper
     */
    private function audit(string $action, int $targetId, array $metadata = []): void
    {
        $actorUserId = Auth::guard('user')->id() ?? Auth::id();
        $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
        
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => 'tax_rate',
            'target_id' => $targetId,
            'metadata' => $metadata,
        ]);
    }
}
