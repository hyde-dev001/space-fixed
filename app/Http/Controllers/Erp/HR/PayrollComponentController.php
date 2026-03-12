<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Services\HR\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * PayrollComponentController
 *
 * Manages individual earnings / deduction / benefit line-items on a payroll record.
 * All mutations are restricted to payrolls in 'pending' status so that approved or
 * paid payrolls remain immutable.
 */
class PayrollComponentController extends Controller
{
    protected PayrollService $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    // ============================================================
    // AUTH HELPER
    // ============================================================

    private function authorizeUser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $user = Auth::guard('user')->user();

        if (
            ! $user->hasRole('Manager')
            && ! $user->can('view-employees')
            && ! $user->can('view-attendance')
            && ! $user->can('view-payroll')
        ) {
            return null;
        }

        return $user;
    }

    // ============================================================
    // ENDPOINTS
    // ============================================================

    /**
     * List all components for a payroll, grouped by type.
     */
    public function getComponents(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with(['components' => fn ($q) => $q->orderBy('component_type')->orderBy('component_name')])
            ->findOrFail($id);

        $components = $payroll->components->groupBy('component_type');

        return response()->json([
            'payroll_id' => $payroll->id,
            'employee'   => $payroll->employee,
            'components' => [
                'earnings'   => $components->get(PayrollComponent::TYPE_EARNING, collect()),
                'deductions' => $components->get(PayrollComponent::TYPE_DEDUCTION, collect()),
                'benefits'   => $components->get(PayrollComponent::TYPE_BENEFIT, collect()),
            ],
            'totals' => [
                'total_earnings'   => $components->get(PayrollComponent::TYPE_EARNING, collect())->sum('calculated_amount'),
                'total_deductions' => $components->get(PayrollComponent::TYPE_DEDUCTION, collect())->sum('calculated_amount'),
                'total_benefits'   => $components->get(PayrollComponent::TYPE_BENEFIT, collect())->sum('calculated_amount'),
                'gross_salary'     => $payroll->gross_salary,
                'tax_amount'       => $payroll->tax_amount,
                'net_salary'       => $payroll->net_salary,
            ],
        ]);
    }

    /**
     * Add a custom component to an existing pending payroll.
     */
    public function addComponent(Request $request, $id): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot add components to non-pending payroll'], 422);
        }

        $validator = Validator::make($request->all(), [
            'component_type' => 'required|in:' . implode(',', [
                PayrollComponent::TYPE_EARNING,
                PayrollComponent::TYPE_DEDUCTION,
                PayrollComponent::TYPE_BENEFIT,
            ]),
            'component_name' => 'required|string|max:100',
            'amount'         => 'required|numeric',
            'is_taxable'     => 'sometimes|boolean',
            'description'    => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $component = PayrollComponent::create([
            'payroll_id'       => $payroll->id,
            'shop_owner_id'    => $user->shop_owner_id,
            'component_type'   => $request->component_type,
            'component_name'   => $request->component_name,
            'amount'           => $request->amount,
            'calculation_method' => PayrollComponent::CALC_FIXED,
            'is_taxable'       => $request->get('is_taxable', false),
            'description'      => $request->description,
        ]);

        $this->recalculateTotals($payroll);

        return response()->json([
            'message'   => 'Component added successfully',
            'component' => $component,
            'payroll'   => $payroll->fresh(),
        ], 201);
    }

    /**
     * Update amount / taxability / description of an existing component.
     * Only allowed while payroll is pending.
     */
    public function updateComponent(Request $request, $payrollId, $componentId): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($payrollId);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot update components of non-pending payroll'], 422);
        }

        $component = PayrollComponent::where('payroll_id', $payrollId)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($componentId);

        $validator = Validator::make($request->all(), [
            'amount'      => 'sometimes|required|numeric',
            'is_taxable'  => 'sometimes|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('amount')) {
            $component->base_amount       = $request->amount;
            $component->calculated_amount = $request->amount;
        }

        if ($request->has('is_taxable')) {
            $component->is_taxable = $request->is_taxable;
        }

        if ($request->has('description')) {
            $component->description = $request->description;
        }

        $component->save();

        $this->recalculateTotals($payroll);

        return response()->json([
            'message'   => 'Component updated successfully',
            'component' => $component,
            'payroll'   => $payroll->fresh(),
        ]);
    }

    /**
     * Delete a component from a pending payroll.
     * Core components (Basic Salary, Income Tax) are protected.
     */
    public function deleteComponent(Request $request, $payrollId, $componentId): JsonResponse
    {
        $user = $this->authorizeUser();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($payrollId);

        if ($payroll->status !== 'pending') {
            return response()->json(['error' => 'Cannot delete components from non-pending payroll'], 422);
        }

        $component = PayrollComponent::where('payroll_id', $payrollId)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($componentId);

        if ($component->is_recurring && in_array($component->component_name, ['Basic Salary', 'Income Tax'])) {
            return response()->json(['error' => 'Cannot delete core payroll components'], 422);
        }

        $component->delete();

        $this->recalculateTotals($payroll);

        return response()->json([
            'message' => 'Component deleted successfully',
            'payroll' => $payroll->fresh(),
        ]);
    }

    // ============================================================
    // PRIVATE HELPER
    // ============================================================

    /**
     * Recompute gross / net / tax after any component change.
     */
    private function recalculateTotals(Payroll $payroll): void
    {
        $payroll->load('components');
        $components = $payroll->components;

        $earnings   = $components->where('component_type', PayrollComponent::TYPE_EARNING)->sum('calculated_amount');
        $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION)->sum('calculated_amount');
        $benefits   = $components->where('component_type', PayrollComponent::TYPE_BENEFIT)->sum('calculated_amount');

        $grossPay     = $earnings + $benefits;
        $taxableAmount = $components->where('is_taxable', true)->sum('calculated_amount');
        $taxAmount    = $this->payrollService->calculateTax($payroll->shop_owner_id, $taxableAmount);
        $netPay       = $grossPay - $deductions - $taxAmount;

        $payroll->update([
            'gross_salary'    => $grossPay,
            'total_deductions' => $deductions,
            'tax_amount'      => $taxAmount,
            'net_salary'      => $netPay,
        ]);

        // Keep the Income Tax component in sync
        $taxComponent = $components->firstWhere('component_name', 'Income Tax');
        if ($taxComponent) {
            $taxComponent->update(['calculated_amount' => $taxAmount]);
        } elseif ($taxAmount > 0) {
            PayrollComponent::create([
                'payroll_id'         => $payroll->id,
                'shop_owner_id'      => $payroll->shop_owner_id,
                'component_type'     => PayrollComponent::TYPE_DEDUCTION,
                'component_name'     => 'Income Tax',
                'base_amount'        => 0,
                'calculation_method' => PayrollComponent::METHOD_CUSTOM,
                'calculated_amount'  => $taxAmount,
                'is_taxable'         => false,
                'is_recurring'       => true,
                'description'        => 'Progressive income tax',
            ]);
        }
    }
}
