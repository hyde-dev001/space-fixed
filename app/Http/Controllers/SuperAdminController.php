<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\SuperAdmin;
use App\Models\ShopOwner;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\SuspensionAppealService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Http\JsonResponse;

class SuperAdminController extends Controller
{
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
    public function showRegisteredShops(): Response
    {
        // Keep list payload lightweight; load document-heavy details on demand.
        $shops = ShopOwner::whereIn('status', ['approved', 'suspended'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($shopOwner) {
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
                    'status' => $shopOwner->status,
                    'suspension_reason' => $shopOwner->suspension_reason,
                    'created_at' => $shopOwner->created_at->format('Y-m-d H:i:s'),
                    'approved_at' => $shopOwner->updated_at->format('Y-m-d H:i:s'),
                ];
            });

        // Calculate statistics
        $stats = [
            'total' => $shops->count(),
            'active' => $shops->where('status', 'approved')->count(),
            'suspended' => $shops->where('status', 'suspended')->count(),
            'thisMonth' => ShopOwner::whereIn('status', ['approved', 'suspended'])
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
            ->with('documents')
            ->findOrFail($id);

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
                'status' => $shopOwner->status,
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

    public function storePremiumPlan(Request $request)
    {
        $data = $this->validatedPremiumPlan($request);
        $data['benefits'] = $this->normalizeBenefits($data['benefits']);
        $data['status'] = 'active';
        PremiumPlan::create($data);

        return redirect()->route('admin.subscription-management')->with('success', 'Premium plan created.');
    }

    public function updatePremiumPlan(Request $request, PremiumPlan $premiumPlan)
    {
        $data = $this->validatedPremiumPlan($request, $premiumPlan);
        unset($data['plan_code']);
        $data['benefits'] = $this->normalizeBenefits($data['benefits']);
        $oldLimit = $premiumPlan->showroom_slot_limit;

        DB::transaction(function () use ($premiumPlan, $data, $oldLimit) {
            $premiumPlan->update($data);

            if ($data['showroom_slot_limit'] > $oldLimit) {
                ShopOwnerSubscription::query()
                    ->where('premium_plan_id', $premiumPlan->id)
                    ->showroomEntitled()
                    ->where('showroom_slot_limit', '<', $data['showroom_slot_limit'])
                    ->update(['showroom_slot_limit' => $data['showroom_slot_limit']]);
            }
        });

        return redirect()->route('admin.subscription-management')->with('success', 'Premium plan updated.');
    }

    public function archivePremiumPlan(PremiumPlan $premiumPlan)
    {
        $premiumPlan->update(['status' => 'inactive']);

        return back()->with('success', 'Premium plan archived.');
    }

    public function reactivatePremiumPlan(PremiumPlan $premiumPlan)
    {
        $premiumPlan->update(['status' => 'active']);

        return back()->with('success', 'Premium plan reactivated.');
    }

    private function validatedPremiumPlan(Request $request, ?PremiumPlan $plan = null): array
    {
        return $request->validate([
            'plan_code' => [$plan ? 'sometimes' : 'required', 'string', 'max:50', Rule::unique('premium_plans', 'plan_code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'between:1,3650'],
            'showroom_slot_limit' => ['required', 'integer', 'between:1,150'],
            'benefits' => ['present', 'array', 'max:20'],
            'benefits.*' => ['required', 'string', 'max:200'],
        ]);
    }

    private function normalizeBenefits(array $benefits): array
    {
        return collect($benefits)
            ->map(fn ($benefit) => trim($benefit))
            ->filter()
            ->values()
            ->all();
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
            } catch (\Exception $e) {
                \Log::error('Error cancelling subscription', [
                    'subscription_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Failed to cancel subscription',
                        'error' => $e->getMessage(),
                    ], 500);
                }

                return redirect()->back()->with('error', 'Failed to cancel subscription');
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
            } catch (\Exception $e) {
                \Log::error('Error upgrading subscription', [
                    'subscription_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Failed to upgrade subscription',
                        'error' => $e->getMessage(),
                    ], 500);
                }

                return redirect()->back()->with('error', 'Failed to upgrade subscription');
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
            } catch (\Exception $e) {
                \Log::error('Error downgrading subscription', [
                    'subscription_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Failed to downgrade subscription',
                        'error' => $e->getMessage(),
                    ], 500);
                }

                return redirect()->back()->with('error', 'Failed to downgrade subscription');
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
    public function showUserManagement(): Response
    {
        // Load users with optional employee relation so Shop Owner suspends reflect here
        $usersQuery = User::orderBy('created_at', 'desc')->with(['employee']);
        $users = $usersQuery->get()->map(function ($u) {
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
                'status' => $u->status ?? 'active',
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
            'active' => collect($users)->where('status', 'active')->count(),
            'suspended' => collect($users)->where('status', 'suspended')->count(),
            'thisMonth' => User::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
        ];

        return Inertia::render('superAdmin/Users/SuperAdminUserManagement', [
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    /**
     * Admin action stubs
     */
    public function suspendUser(Request $request, $id, SuspensionAppealService $suspensionAppealService)
    {
        $validated = $request->validate([
            'suspension_reason' => 'nullable|string|max:1000',
        ]);

        try {
            $user = User::findOrFail($id);
            $user->update(['status' => 'suspended']);

            $suspensionReason = $validated['suspension_reason'] ?? null;
            $suspensionAppealService->createAndSendForCustomer(
                $user,
                $suspensionReason,
                auth('super_admin')->id()
            );

            // If this user is associated with an employee record, mark employee as inactive too
            try {
                $employee = \App\Models\Employee::where('email', $user->email)->first();
                if ($employee) {
                    $employee->update(['status' => 'inactive']);
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'user_suspended',
                'target_type' => 'user',
                'target_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'name' => $user->name,
                    'suspension_reason' => $suspensionReason,
                ],
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Inertia')) {
                return response()->json(['success' => true, 'message' => 'User suspended successfully.']);
            }

            return redirect()->back()->with('success', 'User suspended successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to suspend user.']);
        }
    }

    public function activateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $user->update(['status' => 'active']);

            // If this user is associated with an employee record, mark employee as active too
            try {
                $employee = \App\Models\Employee::where('email', $user->email)->first();
                if ($employee) {
                    $employee->update(['status' => 'active']);
                }
            } catch (\Exception $e) {
                // non-fatal
            }

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'user_activated',
                'target_type' => 'user',
                'target_id' => $user->id,
                'metadata' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Inertia')) {
                return response()->json(['success' => true, 'message' => 'User activated successfully.']);
            }

            return redirect()->back()->with('success', 'User activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to activate user.']);
        }
    }

    /**
     * Return JSON list of users for polling
     */
    public function usersList(Request $request)
    {
        $status = $request->query('status');
        $query = User::orderBy('created_at', 'desc')
            ->whereNull('shop_owner_id')
            ->with('employee');
        if ($status) {
            $query->where('status', $status);
        }

        $users = $query->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'status' => $u->status ?? 'active',
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

    public function suspendAdmin($id)
    {
        try {
            $admin = SuperAdmin::findOrFail($id);
            $admin->update(['status' => 'suspended']);

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'admin_suspended',
                'target_type' => 'super_admin',
                'target_id' => $admin->id,
                'metadata' => [
                    'email' => $admin->email,
                    'first_name' => $admin->first_name,
                    'last_name' => $admin->last_name,
                ],
            ]);

            return redirect()->back()->with('success', 'Admin suspended successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to suspend admin.']);
        }
    }

    public function activateAdmin($id)
    {
        try {
            $admin = SuperAdmin::findOrFail($id);
            $admin->update(['status' => 'active']);

            AuditLog::create([
                'shop_owner_id' => null,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'admin_activated',
                'target_type' => 'super_admin',
                'target_id' => $admin->id,
                'metadata' => [
                    'email' => $admin->email,
                    'first_name' => $admin->first_name,
                    'last_name' => $admin->last_name,
                ],
            ]);

            return redirect()->back()->with('success', 'Admin activated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to activate admin.']);
        }
    }

    public function suspendShop(Request $request, $id, SuspensionAppealService $suspensionAppealService)
    {
        $validated = $request->validate([
            'suspension_reason' => 'nullable|string|max:1000'
        ]);

        try {
            $shop = ShopOwner::findOrFail($id);
            $shop->update([
                'status' => 'suspended',
                'suspension_reason' => $validated['suspension_reason'] ?? null
            ]);

            $suspensionAppealService->createAndSendForShopOwner(
                $shop,
                $validated['suspension_reason'] ?? null,
                auth('super_admin')->id()
            );

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Shop suspended successfully.',
                    'shop' => [
                        'id' => $shop->id,
                        'status' => 'suspended',
                        'suspension_reason' => $validated['suspension_reason'] ?? null,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Shop suspended successfully.');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to suspend shop. Please try again.',
                ], 500);
            }

            return redirect()->back()->withErrors(['error' => 'Failed to suspend shop. Please try again.']);
        }
    }

    public function activateShop(Request $request, $id)
    {
        try {
            $shop = ShopOwner::findOrFail($id);
            $shop->update([
                'status' => 'approved',
                'suspension_reason' => null
            ]);

            // Audit log activation
            AuditLog::create([
                'shop_owner_id' => $shop->id,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'shop_activated',
                'target_type' => 'shop_owner',
                'target_id' => $shop->id,
                'metadata' => [
                    'email' => $shop->email,
                    'business_name' => $shop->business_name ?? null,
                ],
            ]);

            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Shop activated successfully.',
                    'shop' => [
                        'id' => $shop->id,
                        'status' => 'approved',
                        'suspension_reason' => null,
                    ],
                ]);
            }

            return redirect()->back()->with('success', 'Shop activated successfully.');
        } catch (\Exception $e) {
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to activate shop. Please try again.',
                ], 500);
            }

            return redirect()->back()->withErrors(['error' => 'Failed to activate shop. Please try again.']);
        }
    }

    /**
     * Show flagged accounts page
     */
    public function showFlaggedAccounts(): Response
    {
        return Inertia::render('superAdmin/Users/FlaggedAccounts');
    }

    /**
     * Approve shop owner registration
     */
    public function approveShopOwner(Request $request, $id)
    {
        try {
            $shop = ShopOwner::findOrFail($id);

            $shop->update([
                'status' => 'approved',
            ]);

            // Audit log approval action
            AuditLog::create([
                'shop_owner_id' => $shop->id,
                'actor_user_id' => auth('super_admin')->id(),
                'action' => 'shop_owner_approved',
                'target_type' => 'shop_owner',
                'target_id' => $shop->id,
                'metadata' => [
                    'email' => $shop->email,
                    'business_name' => $shop->business_name ?? null,
                ],
            ]);

            return back()->with('success', 'Shop owner approved successfully');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to approve shop owner']);
        }
    }

    /**
     * Reject shop owner registration
     */
    public function rejectShopOwner(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string'
        ]);

        // TODO: Implement rejection logic
        return back()->with('success', 'Shop owner rejected successfully');
    }

    /**
     * Create a new admin
     */
    public function storeAdmin(Request $request)
    {
        // Backward-compatible endpoint used by admin.create-admin.store route.
        return $this->createAdmin($request);
    }

    /**
     * Create a new admin
     */
    public function createAdmin(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|min:2|max:255',
            'last_name' => 'required|string|min:2|max:255',
            'email' => 'required|email|unique:super_admins,email',
            'phone' => 'required|string|min:10|max:20',
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
            'role' => 'required|string|in:admin,super_admin',
        ], [
            'email.unique' => 'This email address is already registered.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        try {
            // Create the admin account
            $admin = SuperAdmin::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'status' => 'active',
            ]);

            return redirect()->route('admin.admin-management')
                ->with('success', 'Admin account created successfully');
        } catch (\Exception $e) {
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['error' => 'Failed to create admin account. Please try again.']);
        }
    }
}
