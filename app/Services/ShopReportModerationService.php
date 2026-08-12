<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\ShopReportWarningMail;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ShopReportModerationService
{
    private const WARNINGS_BEFORE_SUSPENSION = 3;

    /**
     * The service owns the shop root transaction. Report IDs are caller
     * input, but ownership and state are revalidated while the rows are locked.
     */
    public function __construct(
        private readonly AccountSuspensionService $suspensions,
        private readonly SuspensionAppealService $appeals,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /**
     * @param array<int, int|string> $reportIds
     * @return array{changed: bool, shop: ShopOwner, reports: Collection<int, ShopReport>, action: ShopReportModerationAction, effective_action: string, suspension_id: int|null, appeal: \App\Models\SuspensionAppeal|null}
     */
    public function moderate(
        int $shopOwnerId,
        array $reportIds,
        string $requestedAction,
        ?string $notes,
        SuperAdmin $actor,
        Request $request,
    ): array {
        $reportIds = $this->canonicalReportIds($reportIds);
        $requestedAction = $this->validatedAction($requestedAction);
        $notes = trim((string) ($notes ?? ''));

        if ($requestedAction === 'suspend' && $notes === '') {
            throw new HttpException(422, 'A suspension note is required.');
        }

        $result = DB::transaction(function () use (
            $shopOwnerId,
            $reportIds,
            $requestedAction,
            $notes,
            $actor,
            $request,
        ): array {
            $shop = $this->lockShop($shopOwnerId);
            $existing = $this->findExistingDecision($shopOwnerId, $reportIds, $requestedAction);

            if ($existing) {
                $reports = ShopReport::query()
                    ->whereKey($reportIds)
                    ->orderBy('id')
                    ->get();

                return [
                    'changed' => false,
                    'shop' => $shop,
                    'reports' => $reports,
                    'action' => $existing,
                    'effective_action' => (string) $existing->applied_action,
                    'suspension_id' => $shop->current_suspension_id,
                    'appeal' => null,
                ];
            }

            $reports = $this->lockAndValidateReports($shopOwnerId, $reportIds);
            $warningStrike = null;
            $effectiveAction = $requestedAction;

            if ($requestedAction === 'warn') {
                $warningStrike = $this->nextWarningStrike($shopOwnerId);
                if ($warningStrike >= self::WARNINGS_BEFORE_SUSPENSION) {
                    $effectiveAction = 'suspend';
                }
            }

            if ($effectiveAction === 'suspend' && $notes === '') {
                throw new HttpException(422, 'A suspension note is required.');
            }

            $newStatus = match ($effectiveAction) {
                'dismiss' => 'dismissed',
                'warn' => 'warned',
                'suspend' => 'suspended',
            };

            foreach ($reports as $report) {
                if ((string) $report->getRawOriginal('status') === 'submitted') {
                    $report->forceFill(['status' => 'under_review'])->save();
                }

                $report->forceFill([
                    'status' => $newStatus,
                    'admin_notes' => $notes !== '' ? $notes : null,
                    'reviewed_by' => (int) $actor->getKey(),
                    'reviewed_at' => now(),
                ])->save();
            }

            $action = ShopReportModerationAction::query()->create([
                'shop_owner_id' => (int) $shop->getKey(),
                'actor_id' => (int) $actor->getKey(),
                'requested_action' => $requestedAction,
                'applied_action' => $effectiveAction,
                'report_ids' => $reportIds,
                'decision_key' => $this->decisionKey($shopOwnerId, $reportIds, $requestedAction),
                'warning_strike_number' => $warningStrike,
                'source' => AccountSuspension::SOURCE_RUNTIME,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $suspensionResult = null;
            if ($effectiveAction === 'suspend') {
                $suspensionResult = $this->suspensions->suspendLocked(
                    $shop,
                    $actor,
                    $notes,
                    AccountSuspension::SOURCE_RUNTIME,
                );
            }

            $this->audit->shopReportsModerated(
                request: $request,
                actor: $actor,
                shopOwner: $shop,
                moderationAction: $action,
                reportIds: $reportIds,
                requestedAction: $requestedAction,
                appliedAction: $effectiveAction,
                warningStrikeNumber: $warningStrike,
            );

            return [
                'changed' => true,
                'shop' => $shop->fresh(),
                'reports' => $reports->map(fn (ShopReport $report): ShopReport => $report->fresh()),
                'action' => $action->fresh(),
                'effective_action' => $effectiveAction,
                'suspension_id' => $suspensionResult
                    ? $suspensionResult['suspension']?->getKey()
                    : null,
                'appeal' => $suspensionResult['appeal'] ?? null,
                'suspension_changed' => $suspensionResult['changed'] ?? false,
            ];
        });

        if ($result['changed']) {
            if ($result['effective_action'] === 'warn') {
                $this->sendWarningNotice($result['shop'], $result['reports'], $result['action']->notes);
            } elseif (($result['suspension_changed'] ?? false) && $result['appeal']) {
                $this->appeals->sendSuspensionNotice($result['appeal']);
            }
        }

        return $result;
    }

    /** @param array<int, int|string> $reportIds */
    private function canonicalReportIds(array $reportIds): array
    {
        if ($reportIds === [] || count($reportIds) > 100) {
            throw new HttpException(422, 'Choose between 1 and 100 reports.');
        }

        $ids = [];
        foreach ($reportIds as $reportId) {
            if (is_int($reportId)) {
                $id = $reportId;
            } elseif (is_string($reportId) && preg_match('/^[1-9][0-9]*$/', $reportId) === 1) {
                $id = (int) $reportId;
            } else {
                throw new HttpException(422, 'Report IDs must be positive integers.');
            }

            if ($id <= 0) {
                throw new HttpException(422, 'Report IDs must be positive integers.');
            }

            $ids[] = $id;
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new HttpException(422, 'Report IDs must be distinct.');
        }

        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    private function validatedAction(string $action): string
    {
        if (! in_array($action, ['dismiss', 'warn', 'suspend'], true)) {
            throw new HttpException(422, 'The moderation action is invalid.');
        }

        return $action;
    }

    private function lockShop(int $shopOwnerId): ShopOwner
    {
        if ($shopOwnerId <= 0) {
            throw new HttpException(404, 'The shop was not found.');
        }

        $shop = ShopOwner::query()
            ->withTrashed()
            ->whereKey($shopOwnerId)
            ->lockForUpdate()
            ->first();

        if (! $shop) {
            throw new HttpException(404, 'The shop was not found.');
        }

        if ($shop->trashed()) {
            throw new HttpException(409, 'The shop is unavailable for moderation.');
        }

        return $shop;
    }

    /** @param array<int, int> $reportIds */
    private function lockAndValidateReports(int $shopOwnerId, array $reportIds): Collection
    {
        $reports = ShopReport::query()
            ->whereKey($reportIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lockedIds = $reports->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($lockedIds !== $reportIds) {
            throw new HttpException(409, 'The submitted report set is no longer available.');
        }

        foreach ($reports as $report) {
            if ((int) $report->shop_owner_id !== $shopOwnerId) {
                throw new HttpException(409, 'Every report must belong to the selected shop.');
            }

            if (! in_array((string) $report->getRawOriginal('status'), ['submitted', 'under_review'], true)) {
                throw new HttpException(409, 'A report in the submitted set has already been decided.');
            }
        }

        return $reports;
    }

    private function nextWarningStrike(int $shopOwnerId): int
    {
        $latest = ShopReportModerationAction::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('warning_strike_number')
            ->orderByDesc('warning_strike_number')
            ->lockForUpdate()
            ->first();

        return ((int) ($latest?->warning_strike_number ?? 0)) + 1;
    }

    /** @param array<int, int> $reportIds */
    private function findExistingDecision(int $shopOwnerId, array $reportIds, string $requestedAction): ?ShopReportModerationAction
    {
        $keys = array_map(
            fn (string $action): string => $this->decisionKey($shopOwnerId, $reportIds, $action),
            ['dismiss', 'warn', 'suspend'],
        );

        $existing = ShopReportModerationAction::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('decision_key', $keys)
            ->lockForUpdate()
            ->get();

        foreach ($existing as $action) {
            if ($this->canonicalReportIds((array) $action->report_ids) !== $reportIds) {
                throw new HttpException(409, 'A moderation decision identity collision requires review.');
            }

            if ((string) $action->requested_action !== $requestedAction) {
                throw new HttpException(409, 'The report set already has a conflicting decision.');
            }

            return $action;
        }

        return null;
    }

    /** @param array<int, int> $reportIds */
    private function decisionKey(int $shopOwnerId, array $reportIds, string $requestedAction): string
    {
        return hash('sha256', $shopOwnerId.'|'.implode(',', $reportIds).'|'.$requestedAction);
    }

    /** @param Collection<int, ShopReport> $reports */
    private function sendWarningNotice(ShopOwner $shop, Collection $reports, ?string $notes): void
    {
        $email = trim((string) $shop->email);
        if ($email === '') {
            return;
        }

        $accountName = trim((string) (
            ($shop->business_name ?? '')
            ?: (($shop->first_name ?? '').' '.($shop->last_name ?? ''))
        ));
        $firstReason = (string) ($reports->first()?->reason ?? '');
        $primaryReason = ShopReport::REASON_LABELS[$firstReason]
            ?? ($firstReason !== '' ? $firstReason : 'Policy violation');

        try {
            Mail::to($email)->send(new ShopReportWarningMail(
                accountName: $accountName !== '' ? $accountName : 'Shop Owner',
                reportCount: $reports->count(),
                primaryReason: $primaryReason,
                adminNotes: $notes,
                reviewedAtLabel: now()->format('M d, Y h:i A'),
            ));

            Log::info('Shop report warning email sent', [
                'shop_owner_id' => $shop->id,
                'email' => $email,
                'report_count' => $reports->count(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to send shop report warning email', [
                'shop_owner_id' => $shop->id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
