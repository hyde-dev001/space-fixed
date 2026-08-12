<?php

namespace App\Http\Controllers;

use App\Http\Requests\Privileged\InviteAdministratorRequest;
use App\Http\Requests\Privileged\StorePremiumPlanRequest;
use App\Http\Requests\Privileged\UpdatePremiumPlanRequest;
use App\Http\Requests\SuperAdmin\AccountArchiveRequest;
use App\Http\Requests\SuperAdmin\AccountReactivationRequest;
use App\Http\Requests\SuperAdmin\AccountRestoreRequest;
use App\Http\Requests\SuperAdmin\AccountSuspensionRequest;
use App\Enums\PrivilegedDeliveryType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Models\ShopOwner;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\User;
use App\Services\PrivilegedAudit;
use App\Services\AdministratorIdentityService;
use App\Services\AccountLifecycleService;
use App\Services\PrivilegedMailDispatcher;
use App\Services\PrivilegedSecurityTokenService;
use App\Services\PremiumPlanManagementService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SuperAdminController extends Controller
{
    public function __construct(
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedAudit $privilegedAudit,
        private readonly AdministratorIdentityService $identity,
        private readonly AccountLifecycleService $accountLifecycle,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
        private readonly PremiumPlanManagementService $premiumPlans,
        private readonly PrivilegedFailureResponse $failures,
    ) {
    }

    /**
     * Show create admin form
     */
    public function showCreateAdmin(): Response
    {
        return Inertia::render('superAdmin/AdminTeam/CreateAdmin');
    }
    /**
     * Show admin management page
     */
    public function showAdminManagement(): Response
    {
        // Get current authenticated admin ID
        $currentAdminId = auth('super_admin')->id();

        // Fetch all super admins except the currently logged in one
        $admins = SuperAdmin::where('id', '!=', $currentAdminId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($admin) {
                return [
                    'id' => $admin->id,
                    'firstName' => $admin->first_name,
                    'lastName' => $admin->last_name,
                    'role' => $admin->role,
                    'email' => $admin->email,
                    'status' => $admin->status,
                    'mfa_complete' => $admin->hasCompletedMfaSetup(),
                    'recovery_code_count' => is_array($admin->mfa_recovery_codes)
                        ? count($admin->mfa_recovery_codes)
                        : 0,
                    'createdAt' => $admin->created_at->format('Y-m-d H:i:s'),
                    'lastLogin' => $admin->last_login ? $admin->last_login->format('Y-m-d H:i:s') : null,
                ];
            });

        // Calculate statistics
        $stats = [
            'total' => $admins->count(),
            'active' => $admins->where('status', 'active')->count(),
            'suspended' => $admins->where('status', 'suspended')->count(),
            'inactive' => $admins->where('status', 'inactive')->count(),
        ];

        return Inertia::render('superAdmin/AdminTeam/AdminManagement', [
            'admins' => $admins,
            'stats' => $stats
        ]);
    }

    /**
     * Show shop registrations page
     */
    public function showShopRegistrations(): Response
    {
        return Inertia::render('superAdmin/ShopRegistrations', [
            'registrations' => []
        ]);
    }

    /**
     * Show registered shops page
     */
    public function showRegisteredShops(Request $request): Response
    {
        $lifecycle = $request->query('lifecycle', 'all');
        if (!in_array($lifecycle, ['active', 'archived', 'all'], true)) {
            $lifecycle = 'all';
        }

        // Keep list payload lightweight; load document-heavy details on demand.
        $shopsQuery = ShopOwner::withTrashed()
            ->whereIn('status', ['approved', 'suspended']);

        if ($lifecycle === 'active') {
            $shopsQuery->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $shopsQuery->whereNotNull('deleted_at');
        }

        $shops = $shopsQuery
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($shopOwner) {
                $accountStatus = $shopOwner->status;
                $archived = $shopOwner->trashed();

                return [
                    'id' => $shopOwner->id,
                    'first_name' => $shopOwner->first_name,
                    'last_name' => $shopOwner->last_name,
                    'fullName' => $shopOwner->full_name,
                    'email' => $shopOwner->email,
                    'contact_number' => $shopOwner->phone,
                    'phone' => $shopOwner->phone,
                    'business_name' => $shopOwner->business_name,
                    'business_address' => $shopOwner->business_address,
                    'business_type' => $shopOwner->business_type,
                    'registration_type' => $shopOwner->registration_type,
                    'status' => $archived ? 'archived' : $accountStatus,
                    'accountStatus' => $accountStatus,
                    'archived' => $archived,
                    'suspension_reason' => $shopOwner->suspension_reason,
                    'created_at' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                    'approved_at' => $shopOwner->updated_at->format('Y-m-d H:i:s'),
                ];
            });

        // Calculate statistics
        $stats = [
            'total' => $shops->count(),
            'active' => $shops->where('accountStatus', 'approved')->where('archived', false)->count(),
            'suspended' => $shops->where('accountStatus', 'suspended')->where('archived', false)->count(),
            'archived' => $shops->where('archived', true)->count(),
            'thisMonth' => (clone $shopsQuery)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return Inertia::render('superAdmin/Shops/RegisteredShops', [
            'shops' => $shops,
            'stats' => $stats
        ]);
    }

    public function shopDetails(int $id): JsonResponse
    {
        $shopOwner = ShopOwner::query()
            ->withTrashed()
            ->with('documents')
            ->findOrFail($id);

        $accountStatus = $shopOwner->status;
        $archived = $shopOwner->trashed();

        return response()->json([
            'shop' => [
                'id' => $shopOwner->id,
                'first_name' => $shopOwner->first_name,
                'last_name' => $shopOwner->last_name,
                'fullName' => $shopOwner->full_name,
                'email' => $shopOwner->email,
                'contact_number' => $shopOwner->phone,
                'phone' => $shopOwner->phone,
                'business_name' => $shopOwner->business_name,
                'business_address' => $shopOwner->business_address,
                'business_type' => $shopOwner->business_type,
                'registration_type' => $shopOwner->registration_type,
                'operating_hours' => is_array($shopOwner->operating_hours) ? $shopOwner->operating_hours : [],
                'status' => $archived ? 'archived' : $accountStatus,
                'accountStatus' => $accountStatus,
                'archived' => $archived,
                'suspension_reason' => $shopOwner->suspension_reason,
                'created_at' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                'approved_at' => $shopOwner->updated_at->format('Y-m-d H:i:s'),
                'documentUrls' => $shopOwner->documents->map(function ($doc) use ($shopOwner) {
                    return route('admin.shop-documents.show', [
                        'shopOwner' => $shopOwner->id,
                        'document' => $doc->id,
                    ]);
                })->toArray(),
            ],
        ]);
    }

    /**
     * Show subscription management page
     */
    public function showSubscriptionManagement(): Response
    {
        $now = \Carbon\Carbon::now();
        $hasCancellationColumns = Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
            && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes');
        $hasPlanChangeColumns = Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id')
            && Schema::hasColumn('shop_owner_subscriptions', 'payment_method');

        // Fetch all subscriptions with relations
        $subscriptionModels = \App\Models\ShopOwnerSubscription::with(['shopOwner', 'premiumPlan'])
            ->orderBy('created_at', 'desc')
            ->get();

        $planNameBySubscriptionId = $subscriptionModels->mapWithKeys(function ($item) {
            return [(int) $item->id => $item->premiumPlan?->name];
        });

        $subscriptions = $subscriptionModels
            ->map(function ($subscription) use ($now, $hasCancellationColumns, $hasPlanChangeColumns, $planNameBySubscriptionId) {
                $effectiveEndsAt = $subscription->ends_at;
                if (!$effectiveEndsAt && $subscription->starts_at && $subscription->premiumPlan) {
                    $effectiveEndsAt = $subscription->starts_at->copy()->addDays((int) $subscription->premiumPlan->duration_days);
                }

                $nextBillingAt = null;
                if (!in_array($subscription->status, ['deactivated', 'cancelled', 'expired', 'failed'], true) && $effectiveEndsAt?->greaterThanOrEqualTo($now)) {
                    $nextBillingAt = $effectiveEndsAt;
                }

                $cancellationReason = $hasCancellationColumns ? $subscription->cancellation_reason : null;
                $cancellationNotes = $hasCancellationColumns ? $subscription->cancellation_notes : null;

                if ((empty($cancellationReason) || empty($cancellationNotes)) && class_exists(\Spatie\Activitylog\Models\Activity::class)) {
                    $activity = \Spatie\Activitylog\Models\Activity::query()
                        ->where('subject_type', \App\Models\ShopOwnerSubscription::class)
                        ->where('subject_id', $subscription->id)
                        ->where(function ($query) {
                            $query->where('description', 'like', '%cancel%')
                                ->orWhere('description', 'like', '%deactivat%');
                        })
                        ->latest('id')
                        ->first();

                    $cancellationReason = $cancellationReason ?: data_get($activity, 'properties.reason');
                    $cancellationNotes = $cancellationNotes ?: data_get($activity, 'properties.notes');
                }

                $replacesSubscriptionId = $hasPlanChangeColumns
                    ? ($subscription->replaces_subscription_id ? (int) $subscription->replaces_subscription_id : null)
                    : null;

                return [
                    'id' => $subscription->id,
                    'shop' => [
                        'id' => $subscription->shopOwner->id,
                        'business_name' => $subscription->shopOwner->business_name,
                        'owner_name' => $subscription->shopOwner->first_name . ' ' . $subscription->shopOwner->last_name,
                        'email' => $subscription->shopOwner->email,
                    ],
                    'premium_plan' => $subscription->premiumPlan ? [
                        'id' => $subscription->premiumPlan->id,
                        'name' => $subscription->premiumPlan->name,
                        'price' => $subscription->premiumPlan->price,
                        'duration_days' => $subscription->premiumPlan->duration_days,
                    ] : null,
                    'plan_code' => $subscription->plan_code,
                    'showroom_slot_limit' => $subscription->showroom_slot_limit,
                    'status' => $subscription->status,
                    'amount_paid' => (float) ($subscription->paid_amount ?? $subscription->premiumPlan?->price ?? 0),
                    'starts_at' => $subscription->starts_at ? $subscription->starts_at->format('Y-m-d H:i:s') : null,
                    'ends_at' => $subscription->ends_at ? $subscription->ends_at->format('Y-m-d H:i:s') : null,
                    'next_billing_at' => $nextBillingAt?->format('Y-m-d H:i:s'),
                    'cancellation_reason' => $cancellationReason,
                    'cancellation_notes' => $cancellationNotes,
                    'payment_method' => $hasPlanChangeColumns ? $subscription->payment_method : null,
                    'replaces_subscription_id' => $replacesSubscriptionId,
                    'previous_plan_name' => $replacesSubscriptionId ? $planNameBySubscriptionId->get($replacesSubscriptionId) : null,
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                ];
            });

        // Calculate statistics
        $isOngoing = function (array $sub) use ($now) {
            if (($sub['status'] ?? null) === 'deactivated') {
                return false;
            }

            if (!empty($sub['ends_at'])) {
                $endDate = \Carbon\Carbon::parse($sub['ends_at']);
                return $endDate->greaterThanOrEqualTo($now);
            }

            return ($sub['status'] ?? null) === 'active';
        };

        $stats = [
            // Keep cards consistent with UI badge logic: ongoing depends on end date, not only raw status.
            'active' => $subscriptions->filter(fn ($sub) => $isOngoing($sub))->count(),
            'expired' => $subscriptions->filter(fn ($sub) => !$isOngoing($sub))->count(),
            // Count all successful paid subscriptions, even if later cancelled/expired.
            'total_revenue' => $subscriptions->whereIn('status', ['active', 'cancelled', 'deactivated', 'expired'])->sum(function ($sub) {
                return $sub['amount_paid'] ?? 0;
            }),
            'expiring_soon' => $subscriptions->filter(function ($sub) use ($isOngoing, $now) {
                if (!$isOngoing($sub) || empty($sub['ends_at'])) {
                    return false;
                }

                $endDate = \Carbon\Carbon::parse($sub['ends_at']);
                return $endDate->betweenIncluded($now, $now->copy()->addDays(7));
            })->count(),
        ];

        $plans = PremiumPlan::query()
            ->withCount(['subscriptions as active_subscriptions_count' => fn ($query) => $query->showroomEntitled()])
            ->orderBy('price')
            ->get();

        return Inertia::render('superAdmin/Shops/SubscriptionManagement', [
            'subscriptions' => $subscriptions,
            'stats' => $stats,
            'plans' => $plans,
        ]);
    }

    public function storePremiumPlan(StorePremiumPlanRequest $request)
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

        return redirect()->route('admin.subscription-management')->with('success', 'Premium plan created.');
    }

    public function updatePremiumPlan(UpdatePremiumPlanRequest $request, PremiumPlan $premiumPlan)
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

        return redirect()->route('admin.subscription-management')->with('success', 'Premium plan updated.');
    }

    public function archivePremiumPlan(Request $request, PremiumPlan $premiumPlan)
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

    public function reactivatePremiumPlan(Request $request, PremiumPlan $premiumPlan)
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

        /**
         * Cancel a subscription
         */
        public function cancelSubscription(Request $request, $id)
        {
            try {
                $validated = $request->validate([
                    'cancellation_reason' => 'nullable|string|max:120',
                    'cancellation_notes' => 'nullable|string|max:1000',
                ]);

                $reason = trim((string) ($validated['cancellation_reason'] ?? ''));
                $notes = trim((string) ($validated['cancellation_notes'] ?? ''));

                $subscription = \App\Models\ShopOwnerSubscription::with('premiumPlan')->findOrFail($id);
                $refundedAmount = (float) ($subscription->paid_amount ?? $subscription->premiumPlan?->price ?? 0);
                $hasCancellationColumns = Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
                    && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes');

                $effectiveEndsAt = $subscription->ends_at;
                if (!$effectiveEndsAt && $subscription->starts_at && $subscription->premiumPlan) {
                    $effectiveEndsAt = $subscription->starts_at->copy()->addDays((int) $subscription->premiumPlan->duration_days);
                }

                $updatePayload = [
                    'status' => 'deactivated',
                    // Admin deactivation refunds the subscription amount from revenue accounting.
                    'paid_amount' => 0,
                    // Keep existing deadline; cancellation should stop renewal, not immediate access.
                    'ends_at' => $effectiveEndsAt,
                ];

                if ($hasCancellationColumns) {
                    $updatePayload['cancellation_reason'] = $reason !== '' ? $reason : null;
                    $updatePayload['cancellation_notes'] = $notes !== '' ? $notes : null;
                }

                $subscription->update($updatePayload);

                activity()
                    ->performedOn($subscription)
                    ->withProperties([
                        'subscription_id' => $subscription->id,
                        'shop_owner_id' => $subscription->shop_owner_id,
                        'plan_code' => $subscription->plan_code,
                        'cancelled_at' => now()->toDateTimeString(),
                        'effective_ends_at' => $effectiveEndsAt?->toDateTimeString(),
                        'refunded_amount' => $refundedAmount,
                        'reason' => $reason !== '' ? $reason : null,
                        'notes' => $notes !== '' ? $notes : null,
                    ])
                    ->log('Subscription deactivated and refunded by admin');

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Subscription cancelled and refunded successfully',
                        'refunded_amount' => $refundedAmount,
                        'subscription' => $subscription,
                    ], 200);
                }

                return redirect()->back();
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                return $this->failures->unexpected(
                    request: $request,
                    operation: 'subscription_cancel',
                    exception: $exception,
                    message: 'Failed to cancel subscription',
                    code: 'subscription_cancel_error',
                );
            }
        }

        /**
         * Upgrade a subscription to a higher plan
         */
        public function upgradeSubscription(Request $request, $id)
        {
            try {
                $validated = $request->validate([
                    'plan_code' => 'required|string',
                ]);

                $subscription = \App\Models\ShopOwnerSubscription::findOrFail($id);
                $newPlan = \App\Models\PremiumPlan::where('plan_code', $validated['plan_code'])->firstOrFail();

                $oldPlanCode = $subscription->plan_code;
                $subscription->update([
                    'premium_plan_id' => $newPlan->id,
                    'plan_code' => $newPlan->plan_code,
                    'showroom_slot_limit' => $newPlan->showroom_slot_limit,
                ]);

                activity()
                    ->performedOn($subscription)
                    ->withProperties([
                        'subscription_id' => $subscription->id,
                        'shop_owner_id' => $subscription->shop_owner_id,
                        'old_plan' => $oldPlanCode,
                        'new_plan' => $newPlan->plan_code,
                        'upgraded_at' => now()->toDateTimeString(),
                    ])
                    ->log("Subscription upgraded from {$oldPlanCode} to {$newPlan->plan_code}");

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Subscription upgraded successfully',
                        'subscription' => $subscription,
                    ], 200);
                }

                return redirect()->back()->with('success', 'Subscription upgraded successfully');
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                return $this->failures->unexpected(
                    request: $request,
                    operation: 'subscription_upgrade',
                    exception: $exception,
                    message: 'Failed to upgrade subscription',
                    code: 'subscription_upgrade_error',
                );
            }
        }

        /**
         * Downgrade a subscription to a lower plan
         */
        public function downgradeSubscription(Request $request, $id)
        {
            try {
                $validated = $request->validate([
                    'plan_code' => 'required|string',
                ]);

                $subscription = \App\Models\ShopOwnerSubscription::findOrFail($id);
                $newPlan = \App\Models\PremiumPlan::where('plan_code', $validated['plan_code'])->firstOrFail();

                $oldPlanCode = $subscription->plan_code;
                $subscription->update([
                    'premium_plan_id' => $newPlan->id,
                    'plan_code' => $newPlan->plan_code,
                    'showroom_slot_limit' => $newPlan->showroom_slot_limit,
                ]);

                activity()
                    ->performedOn($subscription)
                    ->withProperties([
                        'subscription_id' => $subscription->id,
                        'shop_owner_id' => $subscription->shop_owner_id,
                        'old_plan' => $oldPlanCode,
                        'new_plan' => $newPlan->plan_code,
                        'downgraded_at' => now()->toDateTimeString(),
                    ])
                    ->log("Subscription downgraded from {$oldPlanCode} to {$newPlan->plan_code}");

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Subscription downgraded successfully',
                        'subscription' => $subscription,
                    ], 200);
                }

                return redirect()->back()->with('success', 'Subscription downgraded successfully');
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                return $this->failures->unexpected(
                    request: $request,
                    operation: 'subscription_downgrade',
                    exception: $exception,
                    message: 'Failed to downgrade subscription',
                    code: 'subscription_downgrade_error',
                );
            }
        }

    /**
     * Show data reports dashboard (placeholder values for now)
     */
    public function showDataReports(): Response
    {
        return Inertia::render('superAdmin/Reports/DataReportAccess', [
            'stats' => [
                'totalUsers' => 0,
                'totalShopOwners' => 0,
                'pendingApprovals' => 0,
                'approvedShopOwners' => 0,
                'rejectedShopOwners' => 0,
                'recentRegistrations' => 0,
                'recentApprovals' => 0,
                'totalDocuments' => 0,
                'pendingDocuments' => 0,
                'approvedDocuments' => 0,
                'monthlyGrowth' => [
                    'current' => 0,
                    'previous' => 0,
                    'percentage' => 0,
                ],
            ],
        ]);
    }

    /**
     * Show user management page (placeholder values for now)
     */
    public function showUserManagement(Request $request): Response
    {
        $lifecycle = $request->query('lifecycle', 'all');
        if (!in_array($lifecycle, ['active', 'archived', 'all'], true)) {
            $lifecycle = 'all';
        }

        // Load users with optional employee relation so Shop Owner suspends reflect here
        $usersQuery = User::withTrashed()
            ->orderBy('created_at', 'desc')
            ->with(['employee']);

        if ($lifecycle === 'active') {
            $usersQuery->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $usersQuery->whereNotNull('deleted_at');
        }

        $users = $usersQuery->get()->map(function ($u) {
            $accountStatus = $u->status ?? 'active';
            $archived = $u->trashed();

            return [
                'id' => $u->id,
                'firstName' => $u->first_name ?? null,
                'lastName' => $u->last_name ?? null,
                'name' => $u->name,
                'email' => $u->email,
                'address' => $u->address ?? null,
                'phone' => $u->phone ?? null,
                'age' => $u->age ?? null,
                'role' => $u->role ?? null,
                'status' => $archived ? 'archived' : $accountStatus,
                'accountStatus' => $accountStatus,
                'archived' => $archived,
                'validIdUrl' => $u->valid_id_path
                    ? route('admin.users.valid-id.show', ['user' => $u->id])
                    : null,
                'createdAt' => $u->created_at?->toDateTimeString(),
                'lastLogin' => $u->last_login?->toDateTimeString() ?? null,
                'employee' => $u->employee ? [
                    'id' => $u->employee->id,
                    'name' => $u->employee->name,
                    'phone' => $u->employee->phone,
                    'position' => $u->employee->position,
                    'department' => $u->employee->department,
                    'branch' => $u->employee->branch,
                    'functionalRole' => $u->employee->functional_role,
                    'salary' => $u->employee->salary,
                    'hireDate' => $u->employee->hire_date,
                    'status' => $u->employee->status,
                ] : null,
            ];
        })->toArray();

        $stats = [
            'total' => count($users),
            'active' => collect($users)->where('accountStatus', 'active')->where('archived', false)->count(),
            'suspended' => collect($users)->where('accountStatus', 'suspended')->where('archived', false)->count(),
            'archived' => collect($users)->where('archived', true)->count(),
            'thisMonth' => (clone $usersQuery)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        return Inertia::render('superAdmin/Users/SuperAdminUserManagement', [
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    /**
     * Admin action stubs
     */
    public function suspendUser(AccountSuspensionRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->suspend(
                'user',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('suspension_reason'),
            ),
            successMessage: 'User suspended successfully.',
        );
    }

    public function activateUser(AccountReactivationRequest $request, int $id)
    {
        return $this->reactivateUser($request, $id);
    }

    public function reactivateUser(AccountReactivationRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->reactivate(
                'user',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('reactivation_reason'),
            ),
            successMessage: 'User reactivated successfully.',
        );
    }

    public function archiveUser(AccountArchiveRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->archive(
                'user',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('archive_reason'),
            ),
            successMessage: 'User archived successfully.',
        );
    }

    public function restoreUser(AccountRestoreRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->restore(
                'user',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('restore_reason'),
            ),
            successMessage: 'User restored successfully.',
        );
    }

    /**
     * Return JSON list of users for polling
     */
    public function usersList(Request $request)
    {
        $status = $request->query('status');
        $lifecycle = $request->query('lifecycle', 'active');
        if (!in_array($lifecycle, ['active', 'archived', 'all'], true)) {
            $lifecycle = 'active';
        }

        $query = User::withTrashed()
            ->orderBy('created_at', 'desc')
            ->whereNull('shop_owner_id')
            ->with('employee');

        if ($lifecycle === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($lifecycle === 'archived') {
            $query->whereNotNull('deleted_at');
        }

        if ($status && $status !== 'archived') {
            $query->where('status', $status);
        }

        $users = $query->get()->map(function ($u) {
            $accountStatus = $u->status ?? 'active';
            $archived = $u->trashed();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $archived ? 'archived' : $accountStatus,
                'accountStatus' => $accountStatus,
                'archived' => $archived,
                'role' => $u->role ?? null,
                'createdAt' => $u->created_at?->toDateTimeString(),
                'employee' => $u->employee ? [
                    'id' => $u->employee->id,
                    'status' => $u->employee->status,
                ] : null,
            ];
        });

        return response()->json(['data' => $users], 200);
    }

    public function suspendAdmin(Request $request, int $id)
    {
        try {
            $admin = $this->identity->suspend($request, $this->currentPrivilegedActor(), $id);

            return $this->identityMutationResponse($request, $admin, 'Administrator suspended successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function deactivateAdmin(Request $request, int $id)
    {
        try {
            $admin = $this->identity->deactivate($request, $this->currentPrivilegedActor(), $id);

            return $this->identityMutationResponse($request, $admin, 'Administrator deactivated successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function activateAdmin(Request $request, int $id)
    {
        try {
            $admin = $this->identity->activate($request, $this->currentPrivilegedActor(), $id);

            return $this->identityMutationResponse($request, $admin, 'Administrator activation completed.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function updateAdminRole(Request $request, int $id)
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in([
                SuperAdmin::ROLE_ADMIN,
                SuperAdmin::ROLE_SUPER_ADMIN,
            ])],
        ]);

        try {
            $admin = $this->identity->updateRole(
                $request,
                $this->currentPrivilegedActor(),
                $id,
                (string) $validated['role'],
            );

            return $this->identityMutationResponse($request, $admin, 'Administrator role updated successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    public function resetAdminMfa(Request $request, int $id)
    {
        try {
            $admin = $this->identity->resetMfa($request, $this->currentPrivilegedActor(), $id);

            return $this->identityMutationResponse($request, $admin, 'Administrator MFA reset successfully.');
        } catch (Throwable $exception) {
            return $this->identityMutationFailure($request, $exception);
        }
    }

    private function currentPrivilegedActor(): SuperAdmin
    {
        $actor = auth('super_admin')->user();

        if (! $actor instanceof SuperAdmin) {
            throw new AuthorizationException('A privileged actor is required.');
        }

        return $actor;
    }

    private function identityMutationResponse(Request $request, SuperAdmin $admin, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'id' => (int) $admin->getKey(),
                'status' => (string) $admin->status,
                'role' => (string) $admin->role,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function identityMutationFailure(Request $request, Throwable $exception)
    {
        if ($exception instanceof AuthorizationException) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'privileged_identity',
                exception: $exception,
                message: 'The administrator identity operation is not permitted.',
                code: 'privileged_identity_forbidden',
                forceJson: $request->expectsJson(),
                status: 403,
            );
        }

        if ($exception instanceof ValidationException) {
            return $this->failures->validation(
                request: $request,
                message: 'The administrator identity operation could not be completed.',
                code: 'privileged_identity_validation',
                forceJson: $request->expectsJson(),
            );
        }

        return $this->failures->unexpected(
            request: $request,
            operation: 'privileged_identity',
            exception: $exception,
            message: 'The administrator identity operation could not be completed.',
            code: 'privileged_identity_error',
            forceJson: $request->expectsJson(),
        );
    }

    private function runAccountLifecycle(Request $request, callable $action, string $successMessage)
    {
        try {
            $result = $action();
            $account = $result['account'];
            $payload = [
                'success' => true,
                'message' => $successMessage,
                'account' => [
                    'id' => (int) $account->getKey(),
                    'status' => (string) $account->getRawOriginal('status'),
                    'archived' => $account->trashed(),
                ],
            ];

            if (array_key_exists('suspension', $result)) {
                $payload['suspension_id'] = $result['suspension']?->getKey();
            }

            if ($request->expectsJson() || $request->ajax() || $request->header('X-Inertia')) {
                return response()->json($payload);
            }

            return redirect()->back()->with('success', $successMessage);
        } catch (ModelNotFoundException $exception) {
            return $this->failures->notFound(
                request: $request,
                message: 'The requested account was not found.',
                code: 'account_lifecycle_not_found',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $forceJson = $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia');
            if ($status === 409) {
                return $this->failures->conflict(
                    request: $request,
                    operation: 'account_lifecycle',
                    message: 'The account lifecycle operation conflicts with current state.',
                    code: 'account_lifecycle_conflict',
                    forceJson: $forceJson,
                );
            }

            if ($status === 422) {
                return $this->failures->validation(
                    request: $request,
                    message: 'The account lifecycle operation could not be completed.',
                    code: 'account_lifecycle_validation',
                    forceJson: $forceJson,
                );
            }

            return $this->failures->unexpected(
                request: $request,
                operation: 'account_lifecycle',
                exception: $exception,
                message: 'The account lifecycle operation could not be completed.',
                code: 'account_lifecycle_error',
                forceJson: $forceJson,
                status: $status >= 400 && $status < 500 ? $status : 500,
            );
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'account_lifecycle',
                exception: $exception,
                message: 'The account lifecycle operation could not be completed.',
                code: 'account_lifecycle_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }
    }

    public function suspendShop(AccountSuspensionRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->suspend(
                'shop',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('suspension_reason'),
            ),
            successMessage: 'Shop suspended successfully.',
        );
    }

    public function activateShop(AccountReactivationRequest $request, int $id)
    {
        return $this->reactivateShop($request, $id);
    }

    public function reactivateShop(AccountReactivationRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->reactivate(
                'shop',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('reactivation_reason'),
            ),
            successMessage: 'Shop reactivated successfully.',
        );
    }

    public function archiveShop(AccountArchiveRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->archive(
                'shop',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('archive_reason'),
            ),
            successMessage: 'Shop archived successfully.',
        );
    }

    public function restoreShop(AccountRestoreRequest $request, int $id)
    {
        return $this->runAccountLifecycle(
            request: $request,
            action: fn () => $this->accountLifecycle->restore(
                'shop',
                $id,
                $this->currentPrivilegedActor(),
                $request,
                (string) $request->validated('restore_reason'),
            ),
            successMessage: 'Shop restored successfully.',
        );
    }

    /**
     * Show flagged accounts page
     */
    public function showFlaggedAccounts(): Response
    {
        return Inertia::render('superAdmin/Users/FlaggedAccounts');
    }

    /**
     * Create a new admin
     */
    public function storeAdmin(InviteAdministratorRequest $request)
    {
        // Backward-compatible endpoint used by admin.create-admin.store route.
        return $this->createAdmin($request);
    }

    /**
     * Create a new admin
     */
    public function createAdmin(InviteAdministratorRequest $request)
    {
        $validated = $request->validated();
        $actor = $request->user('super_admin');

        try {
            /** @var array{admin: SuperAdmin} $result */
            $result = DB::transaction(function () use ($validated, $actor, $request): array {
                if (! $actor instanceof SuperAdmin) {
                    throw new \RuntimeException('A privileged actor is required.');
                }

                if (SuperAdmin::query()->whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->exists()) {
                    throw new \RuntimeException('The administrator email is already registered.');
                }

                $admin = SuperAdmin::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => Str::random(64),
                    'role' => $validated['role'],
                    'status' => SuperAdmin::STATUS_PENDING_SETUP,
                ]);
                $issued = $this->tokens->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, $actor);
                $this->privilegedAudit->privilegedInvitationCreated($request, $actor, $admin);

                $this->privilegedMailDispatcher->dispatch(
                    type: PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP,
                    businessEventId: 'privileged-admin-setup:'.$admin->getKey().':'.$issued['token']->getKey(),
                    recipientType: 'super_admin',
                    recipientId: (int) $admin->getKey(),
                    payload: [
                        'recipient_name' => trim($admin->first_name.' '.$admin->last_name),
                        'email' => (string) $admin->email,
                        'raw_token' => $issued['raw_token'],
                    ],
                    correlationId: $this->privilegedAudit->correlationId($request),
                );

                return [
                    'admin' => $admin,
                ];
            });
        } catch (Throwable $exception) {
            $response = $this->failures->unexpected(
                request: $request,
                operation: 'privileged_invitation_create',
                exception: $exception,
                message: 'The administrator invitation could not be created.',
                code: 'privileged_invitation_create_error',
            );

            if (! $request->expectsJson()) {
                $response->withInput($request->except('password', 'password_confirmation'));
            }

            return $response;
        }

        return redirect()->route('admin.admin-management')
            ->with('success', 'Administrator invitation created successfully.');
    }

    public function resendInvitation(Request $request, int $id)
    {
        $actor = $request->user('super_admin');

        try {
            /** @var array{admin: SuperAdmin} $result */
            $result = DB::transaction(function () use ($request, $actor, $id): array {
                if (! $actor instanceof SuperAdmin) {
                    throw new \RuntimeException('A privileged actor is required.');
                }

                $admin = SuperAdmin::query()->lockForUpdate()->find($id);
                if (! $admin instanceof SuperAdmin
                    || $admin->status !== SuperAdmin::STATUS_PENDING_SETUP
                    || $admin->hasCompletedMfaSetup()) {
                    throw new \RuntimeException('The administrator is not pending setup.');
                }

                $issued = $this->tokens->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, $actor);
                $this->privilegedAudit->privilegedInvitationResent($request, $actor, $admin);

                $this->privilegedMailDispatcher->dispatch(
                    type: PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP,
                    businessEventId: 'privileged-admin-setup:'.$admin->getKey().':'.$issued['token']->getKey(),
                    recipientType: 'super_admin',
                    recipientId: (int) $admin->getKey(),
                    payload: [
                        'recipient_name' => trim($admin->first_name.' '.$admin->last_name),
                        'email' => (string) $admin->email,
                        'raw_token' => $issued['raw_token'],
                    ],
                    correlationId: $this->privilegedAudit->correlationId($request),
                );

                return [
                    'admin' => $admin,
                ];
            });
        } catch (Throwable $exception) {
            return $this->failures->unexpected(
                request: $request,
                operation: 'privileged_invitation_resend',
                exception: $exception,
                message: 'The setup invitation could not be resent.',
                code: 'privileged_invitation_resend_error',
            );
        }

        return redirect()->route('admin.admin-management')
            ->with('success', 'Administrator setup invitation resent successfully.');
    }
}
