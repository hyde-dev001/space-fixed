<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReviewReport;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FlaggedAccountModerationService
{
    public function __construct(
        private readonly AccountSuspensionService $suspensions,
        private readonly SuspensionAppealService $appeals,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /**
     * The customer is the aggregate root. It is locked before the report so
     * report state and customer suspension identity commit as one decision.
     *
     * @return array{changed: bool, report: ReviewReport, suspension_id: int|null, suspension_changed: bool, appeal: SuspensionAppeal|null}
     */
    public function moderate(
        int $reportId,
        string $action,
        ?string $notes,
        SuperAdmin $actor,
        Request $request,
    ): array {
        $action = $this->validatedAction($action);
        $notes = $this->normalizedNotes($notes);

        if ($action === 'account_suspended' && $notes === null) {
            throw new HttpException(422, 'A suspension reason is required.');
        }

        $result = DB::transaction(function () use ($reportId, $action, $notes, $actor, $request): array {
            $candidate = ReviewReport::query()->whereKey($reportId)->first();
            if (! $candidate) {
                throw new HttpException(404, 'The flagged account report was not found.');
            }

            $customer = User::withTrashed()
                ->whereKey((int) $candidate->user_id)
                ->lockForUpdate()
                ->first();

            if (! $customer || $customer->trashed()) {
                throw new HttpException(409, 'The reported customer is unavailable for moderation.');
            }

            $report = ReviewReport::query()
                ->whereKey($reportId)
                ->lockForUpdate()
                ->first();

            if (! $report || (int) $report->user_id !== (int) $customer->getKey()) {
                throw new HttpException(409, 'The flagged account report changed while it was being reviewed.');
            }

            $status = (string) $report->getRawOriginal('status');

            if ($report->isTerminal()) {
                return $this->terminalRetry($report, $customer, $action, $notes, $actor);
            }

            if ($action === 'mark_reviewed') {
                if ($report->isUnderInvestigation()) {
                    return [
                        'changed' => false,
                        'report' => $report->fresh(),
                        'suspension_id' => null,
                        'suspension_changed' => false,
                        'appeal' => null,
                    ];
                }

                if (! $report->isPendingReview()) {
                    throw new HttpException(409, 'Only pending reports can enter investigation.');
                }

                $report->forceFill(['status' => ReviewReport::STATUS_UNDER_INVESTIGATION])->save();
                $this->audit->flaggedAccountModerated(
                    request: $request,
                    actor: $actor,
                    report: $report,
                    customer: $customer,
                    action: $action,
                    priorStatus: $status,
                    newStatus: ReviewReport::STATUS_UNDER_INVESTIGATION,
                    suspensionId: null,
                );

                return [
                    'changed' => true,
                    'report' => $report->fresh(),
                    'suspension_id' => null,
                    'suspension_changed' => false,
                    'appeal' => null,
                ];
            }

            if (! $report->isUnderInvestigation()) {
                throw new HttpException(409, 'Only reports under investigation can be resolved.');
            }

            if ($action === 'dismiss') {
                $report->forceFill([
                    'status' => ReviewReport::STATUS_DISMISSED,
                    'admin_notes' => $notes,
                    'resolved_at' => now(),
                ])->save();

                $this->audit->flaggedAccountModerated(
                    request: $request,
                    actor: $actor,
                    report: $report,
                    customer: $customer,
                    action: $action,
                    priorStatus: $status,
                    newStatus: ReviewReport::STATUS_DISMISSED,
                    suspensionId: null,
                );

                return [
                    'changed' => true,
                    'report' => $report->fresh(),
                    'suspension_id' => null,
                    'suspension_changed' => false,
                    'appeal' => null,
                ];
            }

            if ((string) $customer->getRawOriginal('status') !== 'active') {
                throw new HttpException(409, 'Only active customers can receive a new suspension decision.');
            }

            $report->forceFill([
                // The deployed enum remains the compatibility value. The
                // read model exposes this as account_suspended.
                'status' => ReviewReport::STATUS_LEGACY_BANNED,
                'admin_notes' => $notes,
                'resolved_at' => now(),
            ])->save();

            $suspensionResult = $this->suspensions->suspendLocked(
                account: $customer,
                actor: $actor,
                reason: (string) $notes,
            );

            $suspension = $suspensionResult['suspension'];
            $this->audit->flaggedAccountModerated(
                request: $request,
                actor: $actor,
                report: $report,
                customer: $customer,
                action: $action,
                priorStatus: $status,
                newStatus: ReviewReport::STATUS_ACCOUNT_SUSPENDED,
                suspensionId: (int) $suspension->getKey(),
            );

            return [
                'changed' => true,
                'report' => $report->fresh(),
                'suspension_id' => (int) $suspension->getKey(),
                'suspension_changed' => (bool) $suspensionResult['changed'],
                'appeal' => $suspensionResult['appeal'],
            ];
        });

        if ($result['changed'] && $result['suspension_changed'] && $result['appeal']) {
            $this->appeals->sendSuspensionNotice($result['appeal']);
        }

        return $result;
    }

    private function validatedAction(string $action): string
    {
        if (! in_array($action, ['mark_reviewed', 'dismiss', 'account_suspended'], true)) {
            throw new HttpException(422, 'The flagged account decision is invalid.');
        }

        return $action;
    }

    private function normalizedNotes(?string $notes): ?string
    {
        $notes = trim((string) ($notes ?? ''));

        return $notes === '' ? null : $notes;
    }

    /**
     * A terminal row is immutable. Only the same committed operation may be
     * retried, and retries never emit a second audit or suspension notice.
     *
     * @return array{changed: bool, report: ReviewReport, suspension_id: int|null, suspension_changed: bool, appeal: SuspensionAppeal|null}
     */
    private function terminalRetry(
        ReviewReport $report,
        User $customer,
        string $action,
        ?string $notes,
        SuperAdmin $actor,
    ): array {
        $status = (string) $report->getRawOriginal('status');
        $sameDismissal = $status === ReviewReport::STATUS_DISMISSED
            && $action === 'dismiss'
            && $this->sameNotes($report, $notes);
        $sameSuspension = $status === ReviewReport::STATUS_LEGACY_BANNED
            && $action === 'account_suspended'
            && $this->sameNotes($report, $notes)
            && (string) $customer->getRawOriginal('status') === 'suspended'
            && $customer->current_suspension_id !== null;

        if (! $sameDismissal && ! $sameSuspension) {
            throw new HttpException(409, 'A terminal flagged-account decision cannot be reopened or changed.');
        }

        $suspensionResult = $sameSuspension
            ? $this->suspensions->suspendLocked($customer, $actor, (string) $notes)
            : null;

        return [
            'changed' => false,
            'report' => $report->fresh(),
            'suspension_id' => $suspensionResult
                ? (int) $suspensionResult['suspension']->getKey()
                : null,
            'suspension_changed' => false,
            'appeal' => null,
        ];
    }

    private function sameNotes(ReviewReport $report, ?string $notes): bool
    {
        return $this->normalizedNotes($report->admin_notes) === $notes;
    }
}
