<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class ShopDocumentLifecycleService
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $requirements,
    ) {}

    /**
     * Create one immutable pending version. The caller supplies either a new
     * upload or an approved predecessor whose private file is reused.
     *
     * @param array{document_type: string, logical_slot: string, issued_on?: string|null, expiration_mode: string, expires_on?: string|null} $metadata
     */
    public function createPendingVersion(
        ShopOwner $shopOwner,
        array $metadata,
        ?UploadedFile $file = null,
        ?ShopDocument $predecessor = null,
        ?string $submissionKey = null,
    ): ShopDocument {
        $documentType = Str::of((string) ($metadata['document_type'] ?? ''))->trim()->lower()->toString();
        $logicalSlot = Str::of((string) ($metadata['logical_slot'] ?? ''))->trim()->lower()->toString();
        $expectedSlot = $this->requirements->slotForType($documentType);

        if ($documentType === 'supporting_document' && $expectedSlot === null) {
            $expectedSlot = $this->requirements->slotForType($logicalSlot);
        }

        if ($expectedSlot === null || $expectedSlot !== $logicalSlot) {
            throw new ConflictHttpException('The document type and logical slot do not match.');
        }

        $checksum = $file
            ? $this->checksumForUpload($file)
            : $this->checksumForPredecessor($predecessor);

        if ($submissionKey !== null) {
            $existing = ShopDocument::query()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $shopOwner, $documentType, $logicalSlot, $predecessor, $checksum, $metadata);

                return $existing;
            }
        }

        $stagedPath = null;
        try {
            if ($file) {
                $stagedPath = $this->stageUpload($file);
            } elseif (! $predecessor || ! $this->privateFileExists($predecessor)) {
                throw new ConflictHttpException('A reusable private predecessor is required.');
            }

            return DB::transaction(function () use (
                $shopOwner,
                $metadata,
                $documentType,
                $logicalSlot,
                $predecessor,
                $submissionKey,
                $checksum,
                $stagedPath,
            ): ShopDocument {
                $lockedOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->getKey());
                $slotRows = ShopDocument::query()
                    ->whereBelongsTo($lockedOwner)
                    ->where(function ($query) use ($logicalSlot, $predecessor): void {
                        $query->where('logical_slot', $logicalSlot);

                        if ($predecessor) {
                            $query->orWhere($predecessor->getKeyName(), $predecessor->getKey());
                        }
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($submissionKey !== null) {
                    $existing = $slotRows->firstWhere('submission_key', $submissionKey);
                    if ($existing) {
                        $this->assertReplayMatches($existing, $lockedOwner, $documentType, $logicalSlot, $predecessor, $checksum, $metadata);

                        return $existing;
                    }
                }

                $pending = $slotRows->first(
                    fn (ShopDocument $row): bool => (string) $row->status === 'pending'
                        && (bool) $row->is_current === false,
                );
                if ($pending) {
                    throw new ConflictHttpException('A document submission is already pending for this logical slot.');
                }

                $lockedPredecessor = null;
                if ($predecessor) {
                    $lockedPredecessor = $slotRows->firstWhere('id', $predecessor->getKey());
                    if (! $lockedPredecessor || ! $lockedPredecessor->is_current || $lockedPredecessor->status !== 'approved') {
                        throw new ConflictHttpException('The predecessor is no longer the current approved document.');
                    }
                }

                $nextVersion = ((int) ($slotRows->max('version_number') ?? 0)) + 1;
                $document = ShopDocument::create([
                    'shop_owner_id' => $lockedOwner->getKey(),
                    'document_type' => $documentType,
                    'logical_slot' => $logicalSlot,
                    'version_number' => $nextVersion,
                    'predecessor_document_id' => $lockedPredecessor?->getKey(),
                    'file_path' => $stagedPath ?? $lockedPredecessor?->file_path,
                    'disk' => $stagedPath ? 'local' : $lockedPredecessor?->disk,
                    'status' => 'pending',
                    'is_current' => null,
                    'issued_on' => $metadata['issued_on'] ?? null,
                    'expiration_mode' => $metadata['expiration_mode'] ?? null,
                    'expires_on' => $metadata['expires_on'] ?? null,
                    'submission_key' => $submissionKey,
                    'checksum_sha256' => $checksum,
                ]);

                return $document->fresh();
            });
        } catch (Throwable $exception) {
            if ($stagedPath !== null) {
                Storage::disk('local')->delete($stagedPath);
            }

            throw $exception;
        }
    }

    /**
     * Create a complete immutable application/resubmission batch. All slots
     * are locked in lexical slot order after the owner lock so one request
     * cannot race another request into a partial version set.
     *
     * @param array<int, array{metadata: array<string, mixed>, file?: UploadedFile|null, predecessor?: ShopDocument|null, submission_key?: string|null}> $entries
     * @return EloquentCollection<int, ShopDocument>
     */
    public function createPendingVersions(
        ShopOwner $shopOwner,
        array $entries,
        bool $allowHistoricalPredecessor = false,
    ): EloquentCollection
    {
        $prepared = [];
        $stagedPaths = [];

        try {
            foreach ($entries as $entry) {
                $metadata = $entry['metadata'] ?? [];
                $documentType = Str::of((string) ($metadata['document_type'] ?? ''))->trim()->lower()->toString();
                $logicalSlot = Str::of((string) ($metadata['logical_slot'] ?? ''))->trim()->lower()->toString();
                $expectedSlot = $this->requirements->slotForType($documentType);

                if ($documentType === 'supporting_document' && $expectedSlot === null) {
                    $expectedSlot = $this->requirements->slotForType($logicalSlot);
                }

                if ($expectedSlot === null || $expectedSlot !== $logicalSlot) {
                    throw new ConflictHttpException('The document type and logical slot do not match.');
                }

                $file = $entry['file'] ?? null;
                $predecessor = $entry['predecessor'] ?? null;
                $submissionKey = $entry['submission_key'] ?? null;
                $checksum = $file
                    ? $this->checksumForUpload($file)
                    : $this->checksumForPredecessor($predecessor);

                $existing = $submissionKey !== null
                    ? ShopDocument::query()->where('submission_key', $submissionKey)->first()
                    : null;
                if ($existing) {
                    $this->assertReplayMatches($existing, $shopOwner, $documentType, $logicalSlot, $predecessor, $checksum, $metadata);
                }

                $stagedPath = null;
                if (! $existing && $file) {
                    $stagedPath = $this->stageUpload($file);
                    $stagedPaths[] = $stagedPath;
                } elseif (! $existing && ! $predecessor) {
                    throw new ConflictHttpException('A reusable private predecessor or new upload is required.');
                }

                $prepared[] = [
                    'metadata' => $metadata,
                    'document_type' => $documentType,
                    'logical_slot' => $logicalSlot,
                    'predecessor' => $predecessor,
                    'submission_key' => $submissionKey,
                    'checksum' => $checksum,
                    'staged_path' => $stagedPath,
                    'existing' => $existing,
                ];
            }

            $result = DB::transaction(function () use ($shopOwner, $prepared, $allowHistoricalPredecessor): EloquentCollection {
                $lockedOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->getKey());
                $slots = collect($prepared)
                    ->pluck('logical_slot')
                    ->unique()
                    ->sort()
                    ->values();
                $rowsBySlot = collect();

                foreach ($slots as $slot) {
                    $predecessorIds = collect($prepared)
                        ->filter(fn (array $item): bool => $item['logical_slot'] === $slot)
                        ->pluck('predecessor')
                        ->filter(fn ($predecessor): bool => $predecessor instanceof ShopDocument)
                        ->map(fn (ShopDocument $predecessor): int => (int) $predecessor->getKey())
                        ->values()
                        ->all();

                    $rowsBySlot->put($slot, ShopDocument::query()
                        ->whereBelongsTo($lockedOwner)
                        ->where(function ($query) use ($slot, $predecessorIds): void {
                            $query->where('logical_slot', $slot);

                            if ($predecessorIds !== []) {
                                $query->orWhereIn('id', $predecessorIds);
                            }
                        })
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get());
                }

                $created = new EloquentCollection();
                foreach ($prepared as $item) {
                    $slotRows = $rowsBySlot->get($item['logical_slot'], new EloquentCollection());
                    $existing = $item['existing'];

                    if ($existing) {
                        $lockedExisting = $slotRows->firstWhere('submission_key', $item['submission_key']);
                        if (! $lockedExisting) {
                            throw new ConflictHttpException('The submission key is no longer available.');
                        }
                        $this->assertReplayMatches(
                            $lockedExisting,
                            $lockedOwner,
                            $item['document_type'],
                            $item['logical_slot'],
                            $item['predecessor'],
                            $item['checksum'],
                            $item['metadata'],
                        );
                        $created->push($lockedExisting);
                        continue;
                    }

                    $pending = $slotRows->first(
                        fn (ShopDocument $row): bool => (string) $row->status === 'pending'
                            && (bool) $row->is_current === false,
                    );
                    if ($pending) {
                        throw new ConflictHttpException('A document submission is already pending for this logical slot.');
                    }

                    $predecessor = $item['predecessor'];
                    $lockedPredecessor = $predecessor
                        ? $slotRows->firstWhere('id', $predecessor->getKey())
                        : null;
                    if ($predecessor && ! $lockedPredecessor) {
                        throw new ConflictHttpException('The predecessor is no longer available.');
                    }
                    if ($predecessor && ! $allowHistoricalPredecessor
                        && (! $lockedPredecessor->is_current || $lockedPredecessor->status !== 'approved')) {
                        throw new ConflictHttpException('The predecessor is no longer the current approved document.');
                    }
                    if ($predecessor && $allowHistoricalPredecessor
                        && ! in_array((string) $lockedPredecessor->status, ['approved', 'rejected'], true)) {
                        throw new ConflictHttpException('Only terminal predecessor documents can be reused.');
                    }

                    $nextVersion = ((int) ($slotRows->max('version_number') ?? 0)) + 1;
                    $metadata = $item['metadata'];
                    $created->push(ShopDocument::create([
                        'shop_owner_id' => $lockedOwner->getKey(),
                        'document_type' => $item['document_type'],
                        'logical_slot' => $item['logical_slot'],
                        'version_number' => $nextVersion,
                        'predecessor_document_id' => $lockedPredecessor?->getKey(),
                        'file_path' => $item['staged_path'] ?? $lockedPredecessor?->file_path,
                        'disk' => $item['staged_path'] ? 'local' : $lockedPredecessor?->disk,
                        'status' => 'pending',
                        'is_current' => null,
                        'issued_on' => $metadata['issued_on'] ?? null,
                        'expiration_mode' => $metadata['expiration_mode'] ?? null,
                        'expires_on' => $metadata['expires_on'] ?? null,
                        'submission_key' => $item['submission_key'],
                        'checksum_sha256' => $item['checksum'],
                    ]));
                }

                return $created->load('predecessor');
            });

            $stagedPaths = [];

            return $result;
        } catch (Throwable $exception) {
            foreach ($stagedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * Approve and promote a pending version while preserving its predecessor.
     *
     * @param array{document_type?: string, issued_on?: string|null, expiration_mode?: string, expires_on?: string|null} $metadata
     */
    public function approvePendingVersion(
        ShopDocument $candidate,
        int $reviewerId,
        array $metadata = [],
    ): ShopDocument {
        return DB::transaction(function () use ($candidate, $reviewerId, $metadata): ShopDocument {
            $owner = ShopOwner::query()->lockForUpdate()->findOrFail($candidate->shop_owner_id);
            $rows = ShopDocument::query()
                ->whereBelongsTo($owner)
                ->where('logical_slot', $candidate->logical_slot)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedCandidate = $rows->firstWhere('id', $candidate->getKey());

            if (! $lockedCandidate) {
                throw new ConflictHttpException('The document candidate no longer exists.');
            }

            $this->assertPendingCandidate($lockedCandidate);
            if (! $this->privateFileExists($lockedCandidate)) {
                throw new ConflictHttpException('The pending document file is unavailable.');
            }

            $predecessor = $lockedCandidate->predecessor_document_id
                ? $rows->firstWhere('id', $lockedCandidate->predecessor_document_id)
                : null;
            if ($predecessor && (! $predecessor->is_current || $predecessor->status !== 'approved')) {
                throw new ConflictHttpException('The predecessor is no longer current and approved.');
            }

            $current = $rows->first(
                fn (ShopDocument $row): bool => (bool) $row->is_current
                    && (string) $row->status === 'approved'
                    && (int) $row->id !== (int) $lockedCandidate->id,
            );
            if ($current && (! $predecessor || (int) $current->id !== (int) $predecessor->id)) {
                throw new ConflictHttpException('Another current approved document already occupies this slot.');
            }

            if ($predecessor) {
                $predecessor->forceFill([
                    'is_current' => null,
                    'superseded_at' => now(),
                ])->save();
            }

            $lockedCandidate->forceFill([
                'document_type' => $metadata['document_type'] ?? $lockedCandidate->document_type,
                'status' => 'approved',
                'is_current' => true,
                'issued_on' => array_key_exists('issued_on', $metadata) ? $metadata['issued_on'] : $lockedCandidate->issued_on,
                'expiration_mode' => $metadata['expiration_mode'] ?? $lockedCandidate->expiration_mode,
                'expires_on' => array_key_exists('expires_on', $metadata) ? $metadata['expires_on'] : $lockedCandidate->expires_on,
                'reviewed_by_super_admin_id' => $reviewerId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            return $lockedCandidate->fresh();
        });
    }

    public function rejectPendingVersion(
        ShopDocument $candidate,
        int $reviewerId,
        string $reason,
    ): ShopDocument {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ConflictHttpException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($candidate, $reviewerId, $reason): ShopDocument {
            $owner = ShopOwner::query()->lockForUpdate()->findOrFail($candidate->shop_owner_id);
            $rows = ShopDocument::query()
                ->whereBelongsTo($owner)
                ->where(function ($query) use ($candidate): void {
                    $query->where('logical_slot', $candidate->logical_slot)
                        ->orWhere($candidate->getKeyName(), $candidate->getKey())
                        ->orWhere($candidate->getKeyName(), $candidate->predecessor_document_id);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedCandidate = $rows->firstWhere('id', $candidate->getKey());

            if (! $lockedCandidate) {
                throw new ConflictHttpException('The document candidate no longer exists.');
            }

            if ((string) $lockedCandidate->status === 'rejected') {
                if ((string) $lockedCandidate->rejection_reason !== $reason) {
                    throw new ConflictHttpException('This document was already rejected with a different reason.');
                }

                return $lockedCandidate->fresh();
            }

            $this->assertPendingCandidate($lockedCandidate);
            $lockedCandidate->forceFill([
                'status' => 'rejected',
                'is_current' => null,
                'reviewed_by_super_admin_id' => $reviewerId,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $lockedCandidate->fresh();
        });
    }

    private function assertPendingCandidate(ShopDocument $candidate): void
    {
        if ((string) $candidate->status === 'approved') {
            throw new ConflictHttpException('The document decision was already approved.');
        }

        if ((string) $candidate->status !== 'pending' || (bool) $candidate->is_current) {
            throw new ConflictHttpException('Only a pending non-current document can be decided.');
        }
    }

    private function stageUpload(UploadedFile $file): string
    {
        $extension = Str::lower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs('shop_documents', Str::uuid()->toString().'.'.$extension, 'local');

        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            throw new ConflictHttpException('The document could not be stored privately.');
        }

        return $path;
    }

    private function checksumForUpload(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        if (! is_string($realPath) || ! is_file($realPath)) {
            throw new ConflictHttpException('The uploaded document could not be read.');
        }

        $checksum = hash_file('sha256', $realPath);
        if (! is_string($checksum) || $checksum === '') {
            throw new ConflictHttpException('The uploaded document could not be fingerprinted.');
        }

        return $checksum;
    }

    private function checksumForPredecessor(?ShopDocument $predecessor): string
    {
        if (! $predecessor || ! $this->privateFileExists($predecessor)) {
            throw new ConflictHttpException('A reusable private predecessor is required.');
        }

        if (is_string($predecessor->checksum_sha256) && $predecessor->checksum_sha256 !== '') {
            return $predecessor->checksum_sha256;
        }

        $bytes = Storage::disk((string) $predecessor->disk)->get((string) $predecessor->file_path);

        return hash('sha256', $bytes);
    }

    private function privateFileExists(ShopDocument $document): bool
    {
        return (string) $document->disk === 'local'
            && (string) $document->file_path !== ''
            && Storage::disk('local')->exists((string) $document->file_path);
    }

    private function assertReplayMatches(
        ShopDocument $existing,
        ShopOwner $shopOwner,
        string $documentType,
        string $logicalSlot,
        ?ShopDocument $predecessor,
        string $checksum,
        array $metadata = [],
    ): void {
        $same = (int) $existing->shop_owner_id === (int) $shopOwner->getKey()
            && (string) $existing->document_type === $documentType
            && (string) $existing->logical_slot === $logicalSlot
            && (int) ($existing->predecessor_document_id ?? 0) === (int) ($predecessor?->getKey() ?? 0)
            && (string) $existing->checksum_sha256 === $checksum
            && (string) ($existing->issued_on?->toDateString() ?? '') === (string) ($metadata['issued_on'] ?? '')
            && (string) ($existing->expiration_mode ?? '') === (string) ($metadata['expiration_mode'] ?? '')
            && (string) ($existing->expires_on?->toDateString() ?? '') === (string) ($metadata['expires_on'] ?? '');

        if (! $same) {
            throw new ConflictHttpException('The submission key is already used for a different document.');
        }
    }
}
