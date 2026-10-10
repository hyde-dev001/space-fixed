<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privileged\StorePremiumPlanRequest;
use App\Http\Requests\Privileged\UpdatePremiumPlanRequest;
use App\Models\PremiumPlan;
use App\Models\SuperAdmin;
use App\Services\PremiumPlanManagementService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\Request;
use Throwable;

final class PremiumPlanController extends Controller
{
    public function __construct(
        private readonly PremiumPlanManagementService $premiumPlans,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    public function store(StorePremiumPlanRequest $request)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $this->premiumPlans->create($request->validated(), $actor, $request);
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'premium_plan_create',
                exception: $exception,
                message: 'The premium plan could not be created.',
                code: 'premium_plan_create_error',
            );
        }

        return redirect()->route('admin.subscriptions.index')->with('success', 'Premium plan created.');
    }

    public function update(UpdatePremiumPlanRequest $request, PremiumPlan $premiumPlan)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $this->premiumPlans->update($premiumPlan, $request->validated(), $actor, $request);
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'premium_plan_update',
                exception: $exception,
                message: 'The premium plan could not be updated.',
                code: 'premium_plan_update_error',
            );
        }

        return redirect()->route('admin.subscriptions.index')->with('success', 'Premium plan updated.');
    }

    public function archive(Request $request, PremiumPlan $premiumPlan)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $this->premiumPlans->archive($premiumPlan, $actor, $request);
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'premium_plan_archive',
                exception: $exception,
                message: 'The premium plan could not be archived.',
                code: 'premium_plan_archive_error',
            );
        }

        return back()->with('success', 'Premium plan archived.');
    }

    public function reactivate(Request $request, PremiumPlan $premiumPlan)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $this->premiumPlans->reactivate($premiumPlan, $actor, $request);
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'premium_plan_reactivate',
                exception: $exception,
                message: 'The premium plan could not be reactivated.',
                code: 'premium_plan_reactivate_error',
            );
        }

        return back()->with('success', 'Premium plan reactivated.');
    }
}
