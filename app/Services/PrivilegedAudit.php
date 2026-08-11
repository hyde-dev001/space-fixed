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
