<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LegacyShopDocumentReconciler
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $requirements,
    ) {}

    /**
     * Reconcile one owner's legacy rows. The report contains only local
     * identifiers and counters, never names, paths, checksums, or bytes.
     *
     * @return array{owner_id: int, inspected: int, updated: int, already_reconciled: int, unresolved: int, unresolved_ids: array<int, int>, reasons: array<string, int>}
     */
    public function reconcile(ShopOwner $shopOwner, bool $apply): array
    {
        if ($apply) {
            return DB::transaction(function () use ($shopOwner): array {
                $lockedOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->getKey());
                $documents = ShopDocument::query()
                    ->whereBelongsTo($lockedOwner)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                return $this->reconcileDocuments($lockedOwner, $documents, true);
            });
        }

        return $this->reconcileDocuments(
            $shopOwner,
            ShopDocument::query()->whereBelongsTo($shopOwner)->orderBy('id')->get(),
            false,
        );
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, ShopDocument> $documents */
    private function reconcileDocuments(ShopOwner $owner, $documents, bool $apply): array
    {
        $report = [
            'owner_id' => (int) $owner->getKey(),
            'inspected' => $documents->count(),
            'updated' => 0,
            'already_reconciled' => 0,
            'unresolved' => 0,
            'unresolved_ids' => [],
            'reasons' => [],
        ];

        $legacyRows = $documents->filter(fn (ShopDocument $document): bool => $this->needsReconciliation($document));
        $report['already_reconciled'] = $documents->count() - $legacyRows->count();
        $groups = [];

        foreach ($legacyRows as $document) {
            $slot = $this->legacySlot($document);
            if ($slot === null) {
                $this->unresolved($report, $document, 'unsupported_type');
                continue;
            }

            $groups[$slot][] = $document;
        }

        foreach ($groups as $slot => $rows) {
            $rows = collect($rows)->sort(function (ShopDocument $left, ShopDocument $right): int {
                if ($left->created_at === null || $right->created_at === null) {
                    return $left->created_at === null ? -1 : 1;
                }

                $createdComparison = $left->created_at->getTimestamp() <=> $right->created_at->getTimestamp();

                return $createdComparison !== 0
                    ? $createdComparison
                    : ((int) $left->getKey() <=> (int) $right->getKey());
            })->values();

            if ($rows->count() > 1 && $rows->contains(fn (ShopDocument $document): bool => $document->created_at === null)) {
                foreach ($rows as $row) {
                    $this->unresolved($report, $row, 'unorderable_timestamp');
                }
                continue;
            }

            if ($rows->count() > 1 && $this->hasAmbiguousTimestamp($rows)) {
                foreach ($rows as $row) {
                    $this->unresolved($report, $row, 'ambiguous_timestamp');
                }
                continue;
            }

            $approved = $rows->filter(fn (ShopDocument $document): bool => (string) $document->status === 'approved')->values();
            $privateApproved = $approved->filter(fn (ShopDocument $document): bool => $this->hasPrivateFile($document))->values();
            $current = $approved->count() === 1 && $privateApproved->count() === 1
                ? $privateApproved->first()
                : null;

            if ($approved->count() > 1) {
                foreach ($rows as $row) {
                    $this->unresolved($report, $row, 'duplicate_approved_candidates');
                }
            } elseif ($approved->count() === 1 && $privateApproved->count() === 0) {
                foreach ($rows as $row) {
                    $this->unresolved($report, $row, 'approved_file_not_private_or_missing');
                }
            }

            foreach ($rows as $index => $document) {
                $attributes = [
                    'document_type' => $slot === 'business_registration'
                        ? 'legacy_dti_sec_registration'
                        : (string) $document->document_type,
                    'logical_slot' => $slot,
                    'version_number' => $index + 1,
                    'predecessor_document_id' => $index > 0 ? $rows[$index - 1]->getKey() : null,
                    'is_current' => $current && (int) $current->getKey() === (int) $document->getKey()
                        ? true
                        : null,
                    'expiration_mode' => 'unknown',
                    'expires_on' => null,
                ];

                if (! $apply) {
                    continue;
                }

                $changed = false;
                foreach ($attributes as $attribute => $value) {
                    $existing = $document->getAttribute($attribute);
                    if (($existing === null) !== ($value === null) || (string) $existing !== (string) $value) {
                        $changed = true;
                        break;
                    }
                }

                if ($changed) {
                    $document->forceFill($attributes)->save();
                    $report['updated']++;
                }
            }
        }

        return $report;
    }

    private function needsReconciliation(ShopDocument $document): bool
    {
        return $document->logical_slot === null
            || $document->version_number === null
            || $document->expiration_mode === null
            || $document->is_current === null;
    }

    private function legacySlot(ShopDocument $document): ?string
    {
        $existingSlot = trim((string) $document->logical_slot);
        if ($existingSlot !== '') {
            if ($existingSlot === 'business_registration' || $this->requirements->slotForType($existingSlot) === $existingSlot) {
                return $existingSlot;
            }

            if (Str::startsWith($existingSlot, 'supporting_document:')) {
                return $existingSlot;
            }
        }

        $type = $this->requirements->normalizeType((string) $document->document_type);
        if (in_array($type, ['dti_registration', 'sec_registration', 'legacy_dti_sec_registration'], true)) {
            return 'business_registration';
        }

        if (in_array($type, ['mayors_permit', 'bir_certificate', 'valid_id'], true)) {
            return $type;
        }

        if (in_array($type, ['other_supporting_document', 'supporting_document'], true)) {
            return 'supporting_document:legacy:'.$document->getKey();
        }

        return null;
    }

    private function hasPrivateFile(ShopDocument $document): bool
    {
        $disk = trim((string) $document->getRawOriginal('disk'));
        $path = trim((string) $document->getRawOriginal('file_path'));

        if ($disk !== 'local' || $path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }

        try {
            return Storage::disk('local')->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param \Illuminate\Support\Collection<int, ShopDocument> $rows */
    private function hasAmbiguousTimestamp($rows): bool
    {
        return $rows->groupBy(fn (ShopDocument $document): int => $document->created_at?->getTimestamp() ?? -1)
            ->contains(fn ($group): bool => $group->count() > 1);
    }

    /** @param array<string, mixed> $report */
    private function unresolved(array &$report, ShopDocument $document, string $reason): void
    {
        $report['unresolved']++;
        $report['unresolved_ids'][] = (int) $document->getKey();
        $report['reasons'][$reason] = ($report['reasons'][$reason] ?? 0) + 1;
    }
}
