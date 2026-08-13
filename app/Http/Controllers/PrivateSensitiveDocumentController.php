<?php

namespace App\Http\Controllers;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\PrivilegedAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PrivateSensitiveDocumentController extends Controller
{
    private const SUPPORTED_DISKS = ['local', 'public'];

    public function __construct(
        private readonly PrivilegedAudit $privilegedAudit,
    ) {}

    public function showForPrivileged(Request $request, ShopOwner $shopOwner, ShopDocument $document): Response
    {
        $actor = Auth::guard('super_admin')->user();
        if (! $actor instanceof SuperAdmin) {
            abort(401);
        }

        if ((int) $document->shop_owner_id !== (int) $shopOwner->id) {
            abort(404);
        }

        $ownerStatus = $this->statusValue($shopOwner->status);
        $allowed = $actor->role === 'super_admin'
            ? in_array($ownerStatus, ['pending', 'rejected', 'approved', 'suspended'], true)
            : $this->canAdminViewApprovedShopRenewal($shopOwner, $document, $ownerStatus);

        if (! $allowed) {
            abort(404);
        }

        return $this->serve(
            path: (string) $document->file_path,
            disk: (string) $document->disk,
            documentType: (string) $document->document_type,
            recordId: (int) $document->id,
            audit: function (string $mime, string $disposition) use ($request, $actor, $document, $shopOwner): void {
                $this->privilegedAudit->documentAccessInitiated(
                    $request,
                    $actor,
                    $document,
                    $shopOwner,
                    $mime,
                    $disposition,
                );
            },
        );
    }

    public function showCustomerValidId(Request $request, User $user): Response
    {
        $actor = Auth::guard('super_admin')->user();
        if (! $actor instanceof SuperAdmin) {
            abort(401);
        }

        return $this->serve(
            path: (string) $user->valid_id_path,
            disk: (string) $user->valid_id_disk,
            documentType: 'valid_id',
            recordId: (int) $user->id,
            audit: function (string $mime, string $disposition) use ($request, $actor, $user): void {
                $this->privilegedAudit->customerValidIdAccessInitiated(
                    $request,
                    $actor,
                    $user,
                    $mime,
                    $disposition,
                );
            },
        );
    }

    public function showForShopOwner(ShopOwner $shopOwner, ShopDocument $document): Response
    {
        $actor = Auth::guard('shop_owner')->user();
        if (! $actor instanceof ShopOwner || (int) $actor->id !== (int) $shopOwner->id) {
            abort(404);
        }

        if ((int) $document->shop_owner_id !== (int) $shopOwner->id) {
            abort(404);
        }

        return $this->serve(
            path: (string) $document->file_path,
            disk: (string) $document->disk,
            documentType: (string) $document->document_type,
            recordId: (int) $document->id,
            audit: function (string $mime, string $disposition) use ($document, $shopOwner): void {
                $this->writeShopOwnerAudit($document, $shopOwner, 'shop_owner', $mime, $disposition);
            },
        );
    }

    public function showForSignedResubmission(ShopOwner $shopOwner, ShopDocument $document): Response
    {
        if ($this->statusValue($shopOwner->status) !== 'rejected') {
            abort(404);
        }

        if ((int) $document->shop_owner_id !== (int) $shopOwner->id) {
            abort(404);
        }

        return $this->serve(
            path: (string) $document->file_path,
            disk: (string) $document->disk,
            documentType: (string) $document->document_type,
            recordId: (int) $document->id,
            audit: function (string $mime, string $disposition) use ($document, $shopOwner): void {
                $this->writeShopOwnerAudit($document, $shopOwner, 'signed_resubmission_link', $mime, $disposition);
            },
        );
    }

    private function serve(
        string $path,
        string $disk,
        string $documentType,
        int $recordId,
        Closure $audit,
    ): Response {
        $disk = trim($disk);
        $path = trim($path);

        if (
            $path === ''
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || ! in_array($disk, self::SUPPORTED_DISKS, true)
        ) {
            abort(404);
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            abort(404);
        }

        $bytes = $storage->get($path);
        [$mime, $disposition, $extension] = $this->inspect($path, $bytes);
        $filename = $this->generatedFilename($documentType, $recordId, $extension);

        // The audit is intentionally outside a catch and before response creation.
        $audit($mime, $disposition);

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function inspect(string $path, string $bytes): array
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $extensionMime = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
        ][$extension] ?? null;

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $isSafe = $extensionMime !== null && $detectedMime === $extensionMime;

        if (! $isSafe) {
            return ['application/octet-stream', 'attachment', 'bin'];
        }

        return [$detectedMime, 'inline', $extension === 'jpeg' ? 'jpg' : $extension];
    }

    private function generatedFilename(string $documentType, int $recordId, string $extension): string
    {
        $safeType = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $documentType));
        $safeType = trim($safeType, '-_') ?: 'document';

        return $safeType.'-'.$recordId.'.'.$extension;
    }

    private function writeShopOwnerAudit(
        ShopDocument $document,
        ShopOwner $shopOwner,
        string $source,
        string $mime,
        string $disposition,
    ): void {
        $event = $source === 'shop_owner'
            ? 'shop_owner_document_access_initiated'
            : 'signed_resubmission_document_access_initiated';
        $isAuthenticatedOwner = $source === 'shop_owner';

        $activity = activity('sensitive_documents')
            ->performedOn($document)
            ->setEvent($event)
            ->withProperties([
                'actor_type' => $isAuthenticatedOwner ? 'shop_owner' : 'signed_resubmission_link',
                'actor_guard' => $isAuthenticatedOwner ? 'shop_owner' : null,
                'actor_id' => $isAuthenticatedOwner ? (int) $shopOwner->id : null,
                'target_type' => 'shop_document',
                'target_id' => (int) $document->id,
                'shop_owner_id' => (int) $shopOwner->id,
                'source' => $source,
                'correlation_id' => (string) Str::uuid(),
                'ip_address' => request()->ip(),
                'mime' => $mime,
                'disposition' => $disposition,
            ]);

        if ($isAuthenticatedOwner) {
            $activity->causedBy($shopOwner);
        }

        $activity->log($event);
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function canAdminViewApprovedShopRenewal(ShopOwner $shopOwner, ShopDocument $document, string $ownerStatus): bool
    {
        if (in_array($ownerStatus, ['pending', 'rejected'], true)) {
            return true;
        }

        if ($ownerStatus !== 'approved') {
            return false;
        }

        if ((string) $document->status === 'pending'
            && ! (bool) $document->is_current
            && $document->predecessor_document_id !== null) {
            return true;
        }

        return ShopDocument::query()
            ->where('shop_owner_id', $shopOwner->getKey())
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('is_current')->orWhere('is_current', false);
            })
            ->where('predecessor_document_id', $document->getKey())
            ->exists();
    }
}
