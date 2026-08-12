<?php

namespace App\Services;

use App\Models\ShopDocument;
use App\Models\AccountSuspension;
use App\Models\ReviewReport;
use App\Models\ShopOwner;
use App\Models\ShopReportModerationAction;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PrivilegedAudit
{
    public function privilegedBootstrapCreated(SuperAdmin $admin, string $correlationId): void
    {
        $this->writeConsoleSecurity(
            event: 'privileged_bootstrap_created',
            subject: $admin,
            correlationId: $correlationId,
        );
    }

    public function privilegedInvitationCreated(Request $request, SuperAdmin $actor, SuperAdmin $subject): void
    {
        $this->writeSecurity(
            event: 'privileged_invitation_created',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedInvitationResent(Request $request, SuperAdmin $actor, SuperAdmin $subject): void
    {
        $this->writeSecurity(
            event: 'privileged_invitation_resent',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedSetupExchangeSucceeded(Request $request, SuperAdmin $subject): void
    {
        $this->writeSecurity(
            event: 'privileged_setup_exchange_succeeded',
            request: $request,
            subject: $subject,
        );
    }

    public function privilegedSetupExchangeFailed(Request $request): void
    {
        $this->writeSecurity(
            event: 'privileged_setup_exchange_failed',
            request: $request,
            properties: ['reason' => 'invalid_or_expired_token'],
        );
    }

    public function privilegedSetupPasswordCompleted(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_setup_password_completed',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedMfaEnrollmentVerified(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_mfa_enrollment_verified',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedMfaEnrollmentStarted(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_mfa_enrollment_started',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedMfaEnrollmentFailed(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_mfa_enrollment_failed',
            request: $request,
            subject: $admin,
            properties: ['reason' => 'invalid_code'],
        );
    }

    public function privilegedMfaEnrollmentCompleted(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_mfa_enrollment_completed',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedPasswordResetRequested(Request $request, ?SuperAdmin $subject = null): void
    {
        $this->writeSecurity(
            event: 'privileged_password_reset_requested',
            request: $request,
            subject: $subject,
        );
    }

    public function privilegedPasswordResetExchangeSucceeded(Request $request, SuperAdmin $subject): void
    {
        $this->writeSecurity(
            event: 'privileged_password_reset_exchange_succeeded',
            request: $request,
            subject: $subject,
        );
    }

    public function privilegedPasswordResetExchangeFailed(Request $request): void
    {
        $this->writeSecurity(
            event: 'privileged_password_reset_exchange_failed',
            request: $request,
            properties: ['reason' => 'invalid_or_expired_token'],
        );
    }

    public function privilegedPasswordResetCompleted(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_password_reset_completed',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedPasswordChangeCompleted(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_password_change_completed',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedRecoveryCodesGenerated(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_recovery_codes_generated',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedRecoveryCodesAcknowledged(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_recovery_codes_acknowledged',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedReauthenticationSucceeded(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_reauthentication_succeeded',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedReauthenticationFailed(
        Request $request,
        ?SuperAdmin $subject = null,
        string $reason = 'invalid_credentials',
    ): void {
        if (! in_array($reason, ['invalid_credentials', 'invalid_code', 'stale_session'], true)) {
            $reason = 'invalid_credentials';
        }

        $this->writeSecurity(
            event: 'privileged_reauthentication_failed',
            request: $request,
            subject: $subject,
            properties: ['reason' => $reason],
        );
    }

    public function privilegedAdministratorSuspended(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_suspended',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedAdministratorDeactivated(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_deactivated',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedAdministratorActivated(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_activated',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedAdministratorReturnedToSetup(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_returned_to_setup',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedAdministratorRoleChanged(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
        string $fromRole,
        string $toRole,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_role_changed',
            request: $request,
            actor: $actor,
            subject: $subject,
            properties: [
                'from_role' => $fromRole,
                'to_role' => $toRole,
            ],
        );
    }

    public function privilegedAdministratorMfaReset(
        Request $request,
        SuperAdmin $actor,
        SuperAdmin $subject,
    ): void {
        $this->writeSecurity(
            event: 'privileged_administrator_mfa_reset',
            request: $request,
            actor: $actor,
            subject: $subject,
        );
    }

    public function privilegedOwnMfaReset(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_own_mfa_reset',
            request: $request,
            actor: $admin,
            subject: $admin,
        );
    }

    public function privilegedLoginSucceeded(Request $request, SuperAdmin $admin): void
    {
        $this->writeSecurity(
            event: 'privileged_login_password_accepted',
            request: $request,
            actor: $admin,
            subject: $admin,
            properties: ['stage' => 'mfa_pending'],
        );
    }

    public function privilegedLoginFailed(Request $request, ?SuperAdmin $subject = null): void
    {
        $this->writeSecurity(
            event: 'privileged_login_failed',
            request: $request,
            subject: $subject,
            properties: ['reason' => 'invalid_credentials'],
        );
    }

    public function privilegedMfaSucceeded(Request $request, SuperAdmin $admin, string $method): void
    {
        $this->writeMfaEvent('privileged_mfa_succeeded', $request, $admin, $method);
    }

    public function privilegedMfaFailed(Request $request, SuperAdmin $subject, string $method): void
    {
        $this->assertMfaMethod($method);

        $this->writeSecurity(
            event: 'privileged_mfa_failed',
            request: $request,
            subject: $subject,
            properties: [
                'method' => $method,
                'reason' => 'invalid_code',
            ],
        );
    }

    public function privilegedMfaRecoveryCodeConsumed(Request $request, SuperAdmin $admin): void
    {
        $this->writeMfaEvent('privileged_mfa_recovery_code_consumed', $request, $admin, 'recovery_code');
    }

    public function documentAccessInitiated(
        Request $request,
        SuperAdmin $actor,
        ShopDocument $document,
        ShopOwner $shopOwner,
        string $mime,
        string $disposition,
    ): void {
        $this->write(
            event: 'document_access_initiated',
            actor: $actor,
            subject: $document,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'shop_owner_id' => (int) $shopOwner->getKey(),
                'document_type' => (string) $document->document_type,
                'mime' => $mime,
                'disposition' => $disposition,
            ],
        );
    }

    public function customerValidIdAccessInitiated(
        Request $request,
        SuperAdmin $actor,
        User $user,
        string $mime,
        string $disposition,
    ): void {
        $this->write(
            event: 'customer_valid_id_access_initiated',
            actor: $actor,
            subject: $user,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'customer_user_id' => (int) $user->getKey(),
                'mime' => $mime,
                'disposition' => $disposition,
            ],
        );
    }

    public function credentialRotatedByConsole(SuperAdmin $actor, string $operationId): void
    {
        if (! Str::isUuid($operationId)) {
            throw new InvalidArgumentException('The console operation ID must be a UUID.');
        }

        $this->write(
            event: 'super_admin_credential_rotated',
            actor: $actor,
            subject: $actor,
            source: 'console',
            correlationId: $operationId,
            ipAddress: null,
            properties: [],
        );
    }

    public function legacyAccountSuspensionReconciled(
        Model $subject,
        string $correlationId,
        string $accountType,
        int $accountId,
        int $suspensionId,
        ?string $priorStatus,
        string $newStatus,
        int $operatorReviewCount,
    ): void {
        $this->writeConsoleEvent(
            event: 'legacy_account_suspension_reconciled',
            subject: $subject,
            correlationId: $correlationId,
            properties: [
                'account_type' => $accountType,
                'account_id' => $accountId,
                'suspension_id' => $suspensionId,
                'prior_status' => $priorStatus,
                'new_status' => $newStatus,
                'operator_review_count' => $operatorReviewCount,
            ],
        );
    }

    public function legacyAppealSuperseded(
        Model $subject,
        string $correlationId,
        string $accountType,
        int $accountId,
        int $appealId,
        string $priorStatus,
        string $newStatus,
        int $ambiguityCount,
    ): void {
        $this->writeConsoleEvent(
            event: 'legacy_appeal_superseded',
            subject: $subject,
            correlationId: $correlationId,
            properties: [
                'account_type' => $accountType,
                'account_id' => $accountId,
                'appeal_id' => $appealId,
                'prior_status' => $priorStatus,
                'new_status' => $newStatus,
                'ambiguity_count' => $ambiguityCount,
            ],
        );
    }

    public function legacyWarningStrikeReconciled(
        Model $subject,
        string $correlationId,
        int $shopOwnerId,
        int $moderationActionId,
        int $legacyAuditLogId,
    ): void {
        $this->writeConsoleEvent(
            event: 'legacy_warning_strike_reconciled',
            subject: $subject,
            correlationId: $correlationId,
            properties: [
                'shop_owner_id' => $shopOwnerId,
                'moderation_action_id' => $moderationActionId,
                'legacy_audit_log_id' => $legacyAuditLogId,
            ],
        );
    }

    /**
     * Record the committed approval transition without recording setup
     * credentials or private document storage details.
     *
     * @param  array<int, int>  $documentIds
     * @param  array<int, string>  $moduleKeys
     */
    public function shopRegistrationApproved(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        array $documentIds = [],
        array $moduleKeys = [],
    ): void {
        $this->write(
            event: 'shop_registration_approved',
            actor: $actor,
            subject: $shopOwner,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'prior_status' => 'pending',
                'new_status' => 'approved',
                'document_ids' => array_values($documentIds),
                'module_keys' => array_values($moduleKeys),
            ],
        );
    }

    /**
     * Record the committed rejection transition without copying private
     * document paths or applicant credentials into the audit payload.
     *
     * @param  array<int, int>  $documentIds
     */
    public function shopRegistrationRejected(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        string $reason,
        array $documentIds = [],
    ): void {
        $this->write(
            event: 'shop_registration_rejected',
            actor: $actor,
            subject: $shopOwner,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'prior_status' => 'pending',
                'new_status' => 'rejected',
                'reason' => $reason,
                'document_ids' => array_values($documentIds),
            ],
        );
    }

    public function userSuspended(
        Request $request,
        SuperAdmin $actor,
        User $user,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('user_suspended', $request, $actor, $user, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function userReactivated(
        Request $request,
        SuperAdmin $actor,
        User $user,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('user_reactivated', $request, $actor, $user, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function userArchived(
        Request $request,
        SuperAdmin $actor,
        User $user,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('user_archived', $request, $actor, $user, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function userRestored(
        Request $request,
        SuperAdmin $actor,
        User $user,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('user_restored', $request, $actor, $user, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function shopSuspended(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('shop_suspended', $request, $actor, $shopOwner, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function shopReactivated(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('shop_reactivated', $request, $actor, $shopOwner, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function shopArchived(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('shop_archived', $request, $actor, $shopOwner, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    public function shopRestored(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->writeAccountLifecycle('shop_restored', $request, $actor, $shopOwner, $priorStatus, $newStatus, $reason, $suspensionId);
    }

    /**
     * Record one exact-set shop-report decision without copying report text,
     * credentials, or private storage paths into the privileged audit log.
     *
     * @param array<int, int> $reportIds
     */
    public function shopReportsModerated(
        Request $request,
        SuperAdmin $actor,
        ShopOwner $shopOwner,
        ShopReportModerationAction $moderationAction,
        array $reportIds,
        string $requestedAction,
        string $appliedAction,
        ?int $warningStrikeNumber,
    ): void {
        $this->write(
            event: 'shop_reports_moderated',
            actor: $actor,
            subject: $shopOwner,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'moderation_action_id' => (int) $moderationAction->getKey(),
                'report_ids' => array_values(array_map('intval', $reportIds)),
                'requested_action' => $requestedAction,
                'applied_action' => $appliedAction,
                'warning_strike_number' => $warningStrikeNumber,
                'moderation_source' => (string) $moderationAction->source,
            ],
        );
    }

    public function flaggedAccountModerated(
        Request $request,
        SuperAdmin $actor,
        ReviewReport $report,
        User $customer,
        string $action,
        string $priorStatus,
        string $newStatus,
        ?int $suspensionId,
    ): void {
        $this->write(
            event: 'flagged_account_moderated',
            actor: $actor,
            subject: $report,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'customer_id' => (int) $customer->getKey(),
                'action' => $action,
                'prior_status' => $priorStatus,
                'new_status' => $newStatus,
                'suspension_id' => $suspensionId,
            ],
        );
    }

    public function suspensionAppealDecided(
        Request $request,
        SuperAdmin $actor,
        SuspensionAppeal $appeal,
        User|ShopOwner $account,
        string $decision,
        ?string $reviewerNotes,
        int $suspensionId,
    ): void {
        $this->write(
            event: 'suspension_appeal_decided',
            actor: $actor,
            subject: $appeal,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'account_type' => $account instanceof ShopOwner
                    ? AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
                    : AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
                'account_id' => (int) $account->getKey(),
                'decision' => $decision,
                'reviewer_notes' => $reviewerNotes,
                'suspension_id' => $suspensionId,
            ],
        );
    }

    private function writeAccountLifecycle(
        string $event,
        Request $request,
        SuperAdmin $actor,
        Model $subject,
        string $priorStatus,
        string $newStatus,
        string $reason,
        ?int $suspensionId,
    ): void {
        $this->write(
            event: $event,
            actor: $actor,
            subject: $subject,
            source: 'http',
            correlationId: $this->correlationId($request),
            ipAddress: $request->ip(),
            properties: [
                'prior_status' => $priorStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'suspension_id' => $suspensionId,
                'account_type' => $subject instanceof ShopOwner
                    ? AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
                    : AccountSuspension::ACCOUNT_TYPE_CUSTOMER,
            ],
        );
    }

    private function write(
        string $event,
        SuperAdmin $actor,
        Model $subject,
        string $source,
        string $correlationId,
        ?string $ipAddress,
        array $properties,
    ): void {
        $baseProperties = [
            'actor_type' => 'super_admin',
            'actor_guard' => 'super_admin',
            'actor_id' => (int) $actor->getKey(),
            'actor_role' => (string) $actor->role,
            'event' => $event,
            'target_type' => Str::snake(class_basename($subject)),
            'target_id' => (int) $subject->getKey(),
            'source' => $source,
            'correlation_id' => $correlationId,
            'ip_address' => $ipAddress,
            'context' => ['ip_address' => $ipAddress],
        ];

        activity('privileged')
            ->causedBy($actor)
            ->performedOn($subject)
            ->setEvent($event)
            ->withProperties(array_merge($properties, $baseProperties))
            ->log($event);
    }

    private function writeMfaEvent(string $event, Request $request, SuperAdmin $admin, string $method): void
    {
        $this->assertMfaMethod($method);
        $this->writeSecurity(
            event: $event,
            request: $request,
            actor: $admin,
            subject: $admin,
            properties: ['method' => $method],
        );
    }

    private function writeSecurity(
        string $event,
        Request $request,
        ?SuperAdmin $actor = null,
        ?SuperAdmin $subject = null,
        array $properties = [],
    ): void {
        $ipAddress = $request->ip();
        $baseProperties = [
            'actor_type' => $actor instanceof SuperAdmin ? 'super_admin' : null,
            'actor_guard' => $actor instanceof SuperAdmin ? 'super_admin' : null,
            'actor_id' => $actor instanceof SuperAdmin ? (int) $actor->getKey() : null,
            'event' => $event,
            'source' => 'http',
            'correlation_id' => $this->correlationId($request),
            'ip_address' => $ipAddress,
            'context' => ['ip_address' => $ipAddress],
        ];

        if ($subject instanceof SuperAdmin) {
            $baseProperties['target_type'] = 'super_admin';
            $baseProperties['target_id'] = (int) $subject->getKey();
        }

        $logger = activity('privileged');

        if ($actor instanceof SuperAdmin) {
            $logger->causedBy($actor);
        }

        if ($subject instanceof SuperAdmin) {
            $logger->performedOn($subject);
        }

        $logger
            ->setEvent($event)
            ->withProperties(array_merge($baseProperties, $properties))
            ->log($event);
    }

    private function writeConsoleSecurity(
        string $event,
        SuperAdmin $subject,
        string $correlationId,
        array $properties = [],
    ): void {
        $this->writeConsoleEvent(
            event: $event,
            subject: $subject,
            correlationId: $correlationId,
            properties: $properties,
        );
    }

    private function writeConsoleEvent(
        string $event,
        Model $subject,
        string $correlationId,
        array $properties = [],
    ): void {
        if (! Str::isUuid($correlationId)) {
            throw new InvalidArgumentException('The privileged audit correlation ID must be a UUID.');
        }

        $targetType = $subject instanceof SuperAdmin
            ? 'super_admin'
            : Str::snake(class_basename($subject));

        $baseProperties = [
            'actor_type' => null,
            'actor_guard' => null,
            'actor_id' => null,
            'event' => $event,
            'target_type' => $targetType,
            'target_id' => (int) $subject->getKey(),
            'source' => 'console',
            'correlation_id' => $correlationId,
            'ip_address' => null,
            'context' => ['ip_address' => null],
        ];

        activity('privileged')
            ->performedOn($subject)
            ->setEvent($event)
            ->withProperties(array_merge($baseProperties, $properties))
            ->log($event);
    }

    private function assertMfaMethod(string $method): void
    {
        if (! in_array($method, ['totp', 'recovery_code'], true)) {
            throw new InvalidArgumentException('The MFA method is not supported.');
        }
    }

    private function correlationId(Request $request): string
    {
        $existing = $request->attributes->get('privileged_audit_correlation_id');

        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $generated = (string) Str::uuid();
        $request->attributes->set('privileged_audit_correlation_id', $generated);

        return $generated;
    }
}
