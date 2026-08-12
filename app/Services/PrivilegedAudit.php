<?php

namespace App\Services;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PrivilegedAudit
{
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
