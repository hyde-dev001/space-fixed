<?php

namespace App\Http\Controllers\superAdmin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\PlatformFeeAdjustment;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePayment;
use App\Models\PlatformFeePaymentRequest;
use App\Models\PlatformFeeRecommendation;
use App\Models\PlatformFeeSetting;
use App\Models\PlatformReliabilityScore;
use App\Models\ShopOwner;
use App\Models\ShopPlatformFeeSetting;
use App\Models\SuperAdmin;
use App\Services\NotificationService;
use App\Services\PlatformBalanceService;
use App\Services\PlatformFeePaymentService;
use App\Services\PlatformFeeRecommendationService;
use App\Services\PlatformFeeSettingsResolver;
use App\Services\PlatformFeeThresholdService;
use App\Services\PlatformReliabilityService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformFeeAdminController extends Controller
{
    public function __construct(
        private readonly PlatformBalanceService $balance,
        private readonly PlatformFeeSettingsResolver $settings,
        private readonly PlatformReliabilityService $reliability,
        private readonly PlatformFeeRecommendationService $recommendations,
        private readonly NotificationService $notifications,
        private readonly PlatformFeePaymentService $payments,
        private readonly PlatformFeeThresholdService $thresholds,
    ) {}

    public function index(): Response
    {
        $shops = ShopOwner::query()->approved()->orderBy('business_name')->orderBy('id')->get();
        $shopIds = $shops->modelKeys();
        $scores = $this->latestByShop(PlatformReliabilityScore::query()->whereIn('shop_owner_id', $shopIds)->get(), 'shop_owner_id');
        $recommendations = PlatformFeeRecommendation::query()
            ->whereIn('shop_owner_id', $shopIds)
            ->where('status', 'pending')
            ->latest('id')
            ->get()
            ->unique('shop_owner_id')
            ->keyBy('shop_owner_id');
        $requests = PlatformFeePaymentRequest::query()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', ['pending_owner_approval', 'owner_approved', 'payment_pending'])
            ->latest('id')
            ->get()
            ->unique('shop_id')
            ->keyBy('shop_id');
        $payments = PlatformFeePayment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', 'paid')
            ->latest('paid_at')
            ->get()
            ->unique('shop_id')
            ->keyBy('shop_id');
        $shopRows = $shops->map(function (ShopOwner $shop) use ($scores, $recommendations, $requests, $payments): array {
            $config = $this->settings->forShop($shop);
            $summary = $this->balance->summary((int) $shop->id);
            $score = $scores->get($shop->id);
            $recommendation = $recommendations->get($shop->id);
            $request = $requests->get($shop->id);
            $payment = $payments->get($shop->id);

            return [
                'id' => (int) $shop->id,
                'name' => $shop->business_name ?: trim("{$shop->first_name} {$shop->last_name}"),
                'shop_type' => $config['shop_type'],
                'reliability_score' => $score ? (string) $score->score : null,
                'outstanding_balance' => $summary['outstanding_balance'],
                'available_credits' => $summary['available_credits'],
                'net_payable' => $summary['net_payable'],
                'credit_summary' => $summary['credit_summary'],
                'credit_movements' => $summary['credit_movements'],
                'latest_credit_movement' => $summary['credit_movements'][0] ?? null,
                'balance_limit' => $summary['balance_limit'],
                'utilization_percentage' => $summary['utilization_percentage'],
                'platform_fee_rate' => $config['platform_fee_rate'],
                'is_restricted' => $summary['is_restricted'],
                'last_payment_at' => $payment?->paid_at?->toISOString(),
                'active_request_status' => $request?->status,
                'score_recommendation' => $score ? $this->reliability->tierFor((string) $score->score, $config['shop_type']) : null,
                'recommendation' => $recommendation ? [
                    'id' => (int) $recommendation->id,
                    'recommended_limit' => (string) $recommendation->recommended_limit,
                    'tier' => $recommendation->tier,
                    'score' => data_get($recommendation->rationale, 'score'),
                ] : null,
            ];
        })->values()->all();
        $sumDecimals = static function (iterable $values): string {
            $total = BigDecimal::zero();
            foreach ($values as $value) {
                $total = $total->plus((string) ($value ?? '0'));
            }

            return $total->toScale(2, RoundingMode::HALF_UP)->__toString();
        };
        $platformFeesGenerated = $sumDecimals(
            PlatformFeeCharge::query()
                ->whereIn('shop_id', $shopIds)
                ->where('source_origin', 'marketplace')
                ->where('status', '!=', 'void')
                ->whereNotNull('finalized_at')
                ->pluck('platform_fee_amount')
                ->merge(PlatformFeeAdjustment::query()->whereIn('shop_id', $shopIds)->pluck('platform_fee_delta')),
        );

        return Inertia::render('superAdmin/PlatformFees', [
            'shops' => $shopRows,
            'metrics' => [
                'platform_fee_earned' => $platformFeesGenerated,
                'total_billed' => $sumDecimals(
                    PlatformFeeCharge::query()
                        ->whereIn('shop_id', $shopIds)
                        ->where('source_origin', 'marketplace')
                        ->where('status', '!=', 'void')
                        ->whereNotNull('finalized_at')
                        ->pluck('total_charge')
                        ->merge(PlatformFeeAdjustment::query()->whereIn('shop_id', $shopIds)->pluck('total_delta')),
                ),
                'collected' => $sumDecimals(
                    PlatformFeePayment::query()
                        ->whereIn('shop_id', $shopIds)
                        ->where('status', 'paid')
                        ->pluck('amount'),
                ),
                'outstanding' => $sumDecimals(collect($shopRows)->pluck('net_payable')),
                'pending_payments' => PlatformFeePayment::query()
                    ->whereIn('shop_id', $shopIds)
                    ->where('status', 'pending')
                    ->count(),
            ],
            'settings' => PlatformFeeSetting::query()->orderBy('scope')->orderBy('shop_type')->get(),
            'defaults' => [
                'platform_fee_rate' => config('platform_fee.defaults.platform_fee_rate'),
                'platform_fee_vat_enabled' => (bool) config('platform_fee.defaults.platform_fee_vat_enabled'),
                'platform_fee_vat_rate' => config('platform_fee.defaults.platform_fee_vat_rate'),
                'warning_threshold_percentage' => config('platform_fee.defaults.warning_threshold_percentage'),
                'critical_threshold_percentage' => config('platform_fee.defaults.critical_threshold_percentage'),
                'enforcement_enabled' => (bool) config('platform_fee.defaults.enforcement_enabled'),
                'balance_limits' => [
                    'individual' => config('platform_fee.shop_types.individual.balance_limit'),
                    'business' => config('platform_fee.shop_types.business.balance_limit'),
                ],
                'reliability' => config('platform_fee.reliability'),
            ],
        ]);
    }

    public function updateSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['platform', 'shop_type'])],
            'shop_type' => ['nullable', Rule::in(['individual', 'business'])],
            'platform_fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'platform_fee_vat_enabled' => ['nullable', 'boolean'],
            'platform_fee_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'balance_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'warning_threshold_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'critical_threshold_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'enforcement_enabled' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'terms_version' => ['nullable', 'string', 'max:80'],
            'terms_text' => ['nullable', 'string', 'max:10000'],
            'reliability_window_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reliability_version' => ['nullable', 'string', 'max:40'],
            'reliability_weights' => ['nullable'],
            'reliability_tiers' => ['nullable'],
        ]);

        if ($validated['scope'] === 'shop_type' && empty($validated['shop_type'])) {
            throw ValidationException::withMessages(['shop_type' => 'Shop type is required for shop-type settings.']);
        }

        $rateFieldsSubmitted = array_key_exists('platform_fee_rate', $validated)
            || array_key_exists('platform_fee_vat_rate', $validated);
        $affectedShops = collect();
        $previousShopRates = [];
        if ($rateFieldsSubmitted) {
            $affectedShops = ShopOwner::query()->approved()->get()
                ->filter(function (ShopOwner $shop) use ($validated): bool {
                    if ($validated['scope'] !== 'shop_type') {
                        return true;
                    }

                    $shopType = strtolower(trim((string) $shop->registration_type));
                    $shopType = $shopType === 'company' ? 'business' : 'individual';

                    return $shopType === $validated['shop_type'];
                })
                ->values();
            $previousShopRates = $affectedShops->mapWithKeys(function (ShopOwner $shop): array {
                $config = $this->settings->forShop($shop);

                return [$shop->id => [
                    'shop' => $shop,
                    'platform_fee_rate' => $config['platform_fee_rate'],
                    'platform_fee_vat_rate' => $config['platform_fee_vat_rate'],
                ]];
            })->all();
        }

        $reliabilityWeightKeys = [
            'payment_history',
            'settlement_timeliness',
            'marketplace_history',
            'refund_performance',
            'dispute_rate',
            'account_activity',
        ];

        foreach (['reliability_weights', 'reliability_tiers'] as $jsonField) {
            if (! array_key_exists($jsonField, $validated) || $validated[$jsonField] === null || $validated[$jsonField] === '') {
                continue;
            }

            $value = is_string($validated[$jsonField])
                ? json_decode($validated[$jsonField], true)
                : $validated[$jsonField];
            if (! is_array($value)) {
                throw ValidationException::withMessages([$jsonField => 'Enter valid JSON for this reliability setting.']);
            }

            if ($jsonField === 'reliability_weights') {
                if (array_diff($reliabilityWeightKeys, array_keys($value)) !== []
                    || array_diff(array_keys($value), $reliabilityWeightKeys) !== []) {
                    throw ValidationException::withMessages([
                        'reliability_weights' => 'Use one percentage for each reliability factor shown on the form.',
                    ]);
                }

                $weights = [];
                foreach ($reliabilityWeightKeys as $weightKey) {
                    $weight = filter_var($value[$weightKey], FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 0, 'max_range' => 100],
                    ]);

                    if ($weight === false) {
                        throw ValidationException::withMessages([
                            'reliability_weights' => 'Reliability weights must be whole percentages from 0 to 100.',
                        ]);
                    }

                    $weights[$weightKey] = $weight;
                }

                if (array_sum($weights) !== 100) {
                    throw ValidationException::withMessages(['reliability_weights' => 'Reliability weights must total 100%.']);
                }

                $validated[$jsonField] = $weights;
                continue;
            }

            if ($value === []) {
                throw ValidationException::withMessages([
                    'reliability_tiers' => 'Add at least one reliability tier.',
                ]);
            }

            $tiers = [];
            $tierKeys = [];
            foreach ($value as $tier) {
                if (! is_array($tier)) {
                    throw ValidationException::withMessages([
                        'reliability_tiers' => 'Each reliability tier needs a name and minimum score.',
                    ]);
                }

                $tierKey = trim((string) ($tier['key'] ?? ''));
                $minimumScore = $tier['minimum_score'] ?? null;
                $recommendedLimit = $tier['recommended_limit'] ?? null;

                if ($tierKey === '' || strlen($tierKey) > 80 || in_array($tierKey, $tierKeys, true)
                    || ! is_numeric($minimumScore) || (float) $minimumScore < 0 || (float) $minimumScore > 100) {
                    throw ValidationException::withMessages([
                        'reliability_tiers' => 'Each tier needs a unique name and a minimum score from 0 to 100.',
                    ]);
                }

                if ($recommendedLimit !== null && $recommendedLimit !== ''
                    && (! is_numeric($recommendedLimit) || (float) $recommendedLimit < 0)) {
                    throw ValidationException::withMessages([
                        'reliability_tiers' => 'Recommended balance limits must be zero or higher.',
                    ]);
                }

                $tierKeys[] = $tierKey;
                $tiers[] = [
                    'key' => $tierKey,
                    'minimum_score' => $minimumScore + 0,
                    'recommended_limit' => $recommendedLimit === null || $recommendedLimit === ''
                        ? null
                        : (string) $recommendedLimit,
                ];
            }

            $validated[$jsonField] = $tiers;
        }

        $actor = $this->actor($request);
        $key = [
            'scope' => $validated['scope'],
            'shop_type' => $validated['scope'] === 'shop_type' ? $validated['shop_type'] : null,
        ];
        $previousSetting = PlatformFeeSetting::query()->where($key)->first();
        unset($validated['scope'], $validated['shop_type']);
        $validated['approved_by'] = $actor->id;
        $validated['approved_at'] = now();

        $setting = PlatformFeeSetting::query()->updateOrCreate($key, $validated);
        $auditedFields = [
            'platform_fee_rate',
            'platform_fee_vat_enabled',
            'platform_fee_vat_rate',
            'balance_limit',
            'warning_threshold_percentage',
            'critical_threshold_percentage',
            'enforcement_enabled',
            'effective_from',
            'terms_version',
            'terms_text',
            'reliability_window_days',
            'reliability_version',
            'reliability_weights',
            'reliability_tiers',
        ];
        $settingChanges = [];
        foreach ($auditedFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $before = $previousSetting?->{$field};
            $after = $setting->{$field};
            if ($before != $after) {
                $settingChanges[$field] = ['before' => $before, 'after' => $after];
            }
        }
        activity()
            ->causedBy($actor)
            ->performedOn($setting)
            ->withProperties([
                'scope' => $key['scope'],
                'shop_type' => $key['shop_type'],
                'changes' => $settingChanges,
            ])
            ->log('platform_fee_settings_updated');

        if ($settingChanges !== []) {
            $this->notifyPlatformFeeSettingsChangedToAdmins($actor, $key, $settingChanges);
        }

        foreach ($previousShopRates as $rateSnapshot) {
            $currentConfig = $this->settings->forShop($rateSnapshot['shop']);
            $changes = [];
            foreach (['platform_fee_rate' => 'Platform Fee rate', 'platform_fee_vat_rate' => 'Platform Fee VAT rate'] as $field => $label) {
                $before = BigDecimal::of((string) $rateSnapshot[$field]);
                $after = BigDecimal::of((string) $currentConfig[$field]);
                if (! $after->isEqualTo($before)) {
                    $direction = $after->isGreaterThan($before) ? 'increased' : 'decreased';
                    $changes[$field] = [
                        'label' => $label,
                        'before' => (string) $rateSnapshot[$field],
                        'after' => (string) $currentConfig[$field],
                        'direction' => $direction,
                    ];
                }
            }

            if ($changes !== []) {
                $changeText = collect($changes)
                    ->map(fn (array $change): string => "{$change['label']} {$change['direction']} from {$change['before']}% to {$change['after']}%.")
                    ->implode(' ');
                $this->notifyPlatformBalanceStakeholders(
                    shopOwner: $rateSnapshot['shop'],
                    title: count($changes) > 1 ? 'Platform Fee rates updated' : array_values($changes)[0]['label'].' '.array_values($changes)[0]['direction'],
                    message: $changeText.' Review the updated rate before your next Platform Balance payment.',
                    data: [
                        'scope' => $key['scope'],
                        'shop_type' => $key['shop_type'],
                        'changes' => $changes,
                        'effective_from' => $setting->effective_from?->toDateString(),
                    ],
                );
            }
        }

        return back()->with('success', 'Platform Fee settings updated.');
    }

    /** @param array<string, mixed> $scope @param array<string, array{before:mixed, after:mixed}> $changes */
    private function notifyPlatformFeeSettingsChangedToAdmins(SuperAdmin $actor, array $scope, array $changes): void
    {
        $actorName = trim(implode(' ', array_filter([
            trim((string) $actor->first_name),
            trim((string) $actor->last_name),
        ]))) ?: (string) $actor->email;
        $scopeLabel = $scope['scope'] === 'shop_type'
            ? ucfirst((string) $scope['shop_type']).' shops'
            : 'all shops';
        $changeText = collect($changes)
            ->map(function (array $change, string $field): string {
                $label = match ($field) {
                    'platform_fee_rate' => 'Platform Fee rate',
                    'platform_fee_vat_enabled' => 'Platform Fee VAT',
                    'platform_fee_vat_rate' => 'Platform Fee VAT rate',
                    'balance_limit' => 'Balance limit',
                    'warning_threshold_percentage' => 'Warning threshold',
                    'critical_threshold_percentage' => 'Critical threshold',
                    'enforcement_enabled' => 'Enforcement',
                    'effective_from' => 'Effective date',
                    'terms_version' => 'Terms version',
                    'terms_text' => 'Terms disclosure',
                    'reliability_window_days' => 'Reliability window',
                    'reliability_version' => 'Reliability version',
                    'reliability_weights' => 'Reliability weights',
                    'reliability_tiers' => 'Reliability tiers',
                    default => $field,
                };

                return "{$label}: {$this->formatSettingValue($change['before'])} to {$this->formatSettingValue($change['after'])}";
            })
            ->implode('; ');

        Notification::notifyAllSuperAdmins(
            type: NotificationType::PLATFORM_BALANCE_ALERT,
            title: 'Platform Fee settings changed',
            message: "Platform Fee settings for {$scopeLabel} were changed by {$actorName} ({$actor->email}). {$changeText}",
            actionUrl: '/admin/platform-fees',
            data: [
                'scope' => $scope['scope'],
                'shop_type' => $scope['shop_type'],
                'changes' => $changes,
                'actor_id' => (int) $actor->id,
                'actor_name' => $actorName,
                'actor_email' => (string) $actor->email,
            ],
        );
    }

    private function formatSettingValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'updated';
        }

        return $value === null || $value === '' ? 'not set' : (string) $value;
    }

    public function approveRecommendation(Request $request, PlatformFeeRecommendation $recommendation): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $shopOwner = ShopOwner::query()->findOrFail($recommendation->shop_owner_id);
        $previousLimit = $this->settings->forShop($shopOwner)['balance_limit'];
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $approved = $this->recommendations->approve($recommendation, (int) $actor->id, $validated['note'] ?? null);
        $newLimit = BigDecimal::of((string) $approved->recommended_limit)->toScale(2, RoundingMode::HALF_UP)->__toString();
        $limitChanged = ! BigDecimal::of((string) $previousLimit)->isEqualTo(BigDecimal::of($newLimit));
        $direction = BigDecimal::of($newLimit)->isGreaterThan(BigDecimal::of((string) $previousLimit))
            ? 'increased'
            : 'decreased';

        activity()
            ->causedBy($actor)
            ->performedOn($approved)
            ->withProperties([
                'shop_owner_id' => $approved->shop_owner_id,
                'old_limit' => $previousLimit,
                'approved_limit' => $newLimit,
                'score' => data_get($approved->rationale, 'score'),
                'note' => $approved->review_note,
            ])
            ->log('platform_fee_limit_recommendation_approved');

        if ($limitChanged) {
            $this->notifyPlatformBalanceStakeholders(
                shopOwner: $shopOwner,
                title: 'Platform Balance limit updated',
                message: "Your Platform Balance limit was {$direction} from {$previousLimit} to {$newLimit} after the reliability review.",
                data: [
                    'old_limit' => (string) $previousLimit,
                    'new_limit' => $newLimit,
                    'direction' => $direction,
                    'recommendation_id' => $approved->id,
                ],
            );
        }

        return back()->with('success', 'Recommended Platform Balance limit approved.');
    }

    public function rejectRecommendation(Request $request, PlatformFeeRecommendation $recommendation): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $rejected = $this->recommendations->reject($recommendation, (int) $actor->id, $validated['note'] ?? null);

        activity()
            ->causedBy($actor)
            ->performedOn($rejected)
            ->withProperties(['shop_owner_id' => $rejected->shop_owner_id, 'note' => $rejected->review_note])
            ->log('platform_fee_limit_recommendation_rejected');

        return back()->with('success', 'Recommendation rejected.');
    }

    public function recalculate(Request $request, ShopOwner $shopOwner): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $score = $this->reliability->recalculate($shopOwner);
        $recommendation = $this->recommendations->recommend($shopOwner);

        activity()
            ->causedBy($actor)
            ->performedOn($score)
            ->withProperties([
                'shop_owner_id' => $shopOwner->id,
                'score' => $score->score,
                'recommendation_id' => $recommendation?->id,
            ])
            ->log('platform_reliability_recalculated');

        return back()->with('success', 'Reliability score recalculated.');
    }

    public function remind(Request $request, ShopOwner $shopOwner): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $config = $this->settings->forShop($shopOwner);
        $active = PlatformFeePaymentRequest::query()
            ->where('shop_id', $shopOwner->id)
            ->whereIn('status', ['pending_owner_approval', 'owner_approved', 'payment_pending'])
            ->latest('id')
            ->first();

        $message = $active
            ? 'A Platform Balance payment request needs your review.'
            : 'Review Platform Balance and submit the required full-payment workflow.';
        $this->notifications->sendToShopOwner(
            shopOwnerId: (int) $shopOwner->id,
            type: NotificationType::PLATFORM_BALANCE_ALERT,
            title: $active ? 'Platform Balance Approval Reminder' : 'Platform Balance Payment Reminder',
            message: $message,
            data: ['payment_request_id' => $active?->id, 'shop_type' => $config['shop_type']],
            actionUrl: '/shop-owner/platform-balance',
            priority: 'high',
            requiresAction: $active !== null,
        );

        if ($config['shop_type'] === 'business') {
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $shopOwner->id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: 'Platform Balance Payment Reminder',
                message: $active ? 'A Platform Balance request is awaiting its next workflow step.' : 'Submit a full Platform Balance payment request for owner approval.',
                data: ['payment_request_id' => $active?->id],
                actionUrl: '/finance/platform-balance',
                priority: 'high',
                requiredPermission: 'access-finance-dashboard',
            );
        }

        activity()
            ->causedBy($actor)
            ->performedOn($shopOwner)
            ->withProperties(['active_request_id' => $active?->id, 'shop_type' => $config['shop_type']])
            ->log('platform_balance_reminder_sent');

        return back()->with('success', 'Platform Balance reminder sent.');
    }

    public function adjust(Request $request, ShopOwner $shopOwner): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'adjustment_type' => ['required', Rule::in(['credit_adjustment', 'debit_adjustment'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : 'platform-admin-adjustment:'.str()->uuid();

        $adjustment = DB::transaction(function () use ($actor, $idempotencyKey, $shopOwner, $validated): PlatformFeeAdjustment {
            $shop = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->id);
            $existing = PlatformFeeAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless((int) $existing->shop_id === (int) $shop->id, 409, 'Adjustment idempotency key belongs to another shop.');

                return $existing;
            }

            $amount = BigDecimal::of((string) $validated['amount'])->toScale(2, RoundingMode::HALF_UP);
            $totalDelta = $validated['adjustment_type'] === 'credit_adjustment'
                ? $amount->negated()
                : $amount;

            return PlatformFeeAdjustment::query()->create([
                'shop_id' => $shop->id,
                'adjustment_type' => $validated['adjustment_type'],
                'source_type' => 'admin',
                'source_id' => $actor->id,
                'fee_base_delta' => '0.00',
                'platform_fee_delta' => '0.00',
                'vat_delta' => '0.00',
                'total_delta' => $totalDelta->__toString(),
                'reason' => trim($validated['reason']),
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);
        });

        if ($adjustment->wasRecentlyCreated) {
            $this->payments->invalidateIfBalanceChanged((int) $shopOwner->id);
            $this->thresholds->evaluate((int) $shopOwner->id);
            activity()
                ->causedBy($actor)
                ->performedOn($adjustment)
                ->withProperties([
                    'shop_owner_id' => $shopOwner->id,
                    'adjustment_type' => $adjustment->adjustment_type,
                    'total_delta' => $adjustment->total_delta,
                    'reason' => $adjustment->reason,
                ])
                ->log('platform_fee_admin_adjustment_created');
        }

        return back()->with('success', 'Platform Balance adjustment recorded.');
    }

    public function updateShopLimit(Request $request, ShopOwner $shopOwner): \Illuminate\Http\RedirectResponse
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'balance_limit' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);
        $previousLimit = $this->settings->forShop($shopOwner)['balance_limit'];
        $newLimit = BigDecimal::of((string) $validated['balance_limit'])->toScale(2, RoundingMode::HALF_UP)->__toString();
        $limitChanged = ! BigDecimal::of((string) $previousLimit)->isEqualTo(BigDecimal::of($newLimit));

        DB::transaction(function () use ($actor, $newLimit, $shopOwner): void {
            ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->id);
            $override = ShopPlatformFeeSetting::query()->firstOrNew([
                'shop_owner_id' => $shopOwner->id,
            ]);
            $override->fill([
                'balance_limit' => $newLimit,
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $override->save();
        });

        if ($limitChanged) {
            $direction = BigDecimal::of($newLimit)->isGreaterThan(BigDecimal::of((string) $previousLimit))
                ? 'increased'
                : 'decreased';
            activity()
                ->causedBy($actor)
                ->performedOn($shopOwner)
                ->withProperties([
                    'old_limit' => $previousLimit,
                    'new_limit' => $newLimit,
                    'direction' => $direction,
                ])
                ->log('platform_fee_shop_limit_updated');

            $this->notifyPlatformBalanceStakeholders(
                shopOwner: $shopOwner,
                title: 'Platform Balance limit updated',
                message: "Your Platform Balance limit was {$direction} from {$previousLimit} to {$newLimit}.",
                data: [
                    'old_limit' => (string) $previousLimit,
                    'new_limit' => $newLimit,
                    'direction' => $direction,
                ],
            );
        }

        return back()->with('success', 'Shop Platform Balance limit updated.');
    }

    /** @param array<string, mixed> $data */
    private function notifyPlatformBalanceStakeholders(ShopOwner $shopOwner, string $title, string $message, array $data): void
    {
        $this->notifications->sendToShopOwner(
            shopOwnerId: (int) $shopOwner->id,
            type: NotificationType::PLATFORM_BALANCE_ALERT,
            title: $title,
            message: $message,
            data: $data,
            actionUrl: '/shop-owner/platform-balance',
            priority: 'high',
        );

        if ($shopOwner->isCompany()) {
            $this->notifications->sendToErpRole(
                roleName: 'Finance',
                shopId: (int) $shopOwner->id,
                type: NotificationType::PLATFORM_BALANCE_ALERT,
                title: $title,
                message: $message,
                data: $data,
                actionUrl: '/finance/platform-balance',
                priority: 'high',
                requiredPermission: 'access-finance-dashboard',
            );
        }
    }

    private function actor(Request $request): SuperAdmin
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 401);

        return $actor;
    }

    private function latestByShop($records, string $key)
    {
        return $records
            ->sortByDesc(fn ($record) => [$record->score_date?->timestamp ?? 0, $record->id])
            ->unique($key)
            ->keyBy($key);
    }
}
