<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\ShopDocument;
use App\Models\ShopDocumentReminderDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ShopDocumentReminderService
{
    /** @var array<int, int> */
    private const THRESHOLDS = [30, 7, 0];

    /**
     * Process one local business date. The query is deliberately limited to
     * versioned, current, approved, reviewer-verified rows with dated expiry.
     *
     * @return array{matched: int, sent: int, skipped: int}
     */
    public function sendForDate(CarbonImmutable $localDate, ?int $shopOwnerId = null, int $chunk = 100): array
    {
        $localDate = $localDate->setTimezone($this->timezone())->startOfDay();
        $targetDates = array_map(
            fn (int $threshold): string => $localDate->addDays($threshold)->toDateString(),
            self::THRESHOLDS,
        );
        $result = ['matched' => 0, 'sent' => 0, 'skipped' => 0];

        $query = ShopDocument::query()
            ->select([
                'id',
                'shop_owner_id',
                'document_type',
                'logical_slot',
                'version_number',
                'status',
                'is_current',
                'reviewed_by_super_admin_id',
                'reviewed_at',
                'expiration_mode',
                'expires_on',
            ])
            ->with('shopOwner:id,business_name,status')
            ->currentApproved()
            ->whereNotNull('logical_slot')
            ->whereNotNull('version_number')
            ->where('expiration_mode', 'dated')
            ->where(function ($dateQuery) use ($targetDates): void {
                foreach ($targetDates as $targetDate) {
                    $dateQuery->orWhereDate('expires_on', $targetDate);
                }
            })
            ->whereHas('shopOwner', fn ($ownerQuery) => $ownerQuery->where('status', 'approved'))
            ->orderBy('id');

        if ($shopOwnerId !== null) {
            $query->where('shop_owner_id', $shopOwnerId);
        }

        $query->chunkById(max(1, min(1000, $chunk)), function (Collection $documents) use (&$result, $localDate): void {
            foreach ($documents as $document) {
                $result['matched']++;
                $threshold = $this->thresholdFor($localDate, $document->expires_on);
                if ($threshold === null || ! $document->shopOwner) {
                    $result['skipped']++;
                    continue;
                }

                if ($this->deliver($document, $threshold)) {
                    $result['sent']++;
                } else {
                    $result['skipped']++;
                }
            }
        });

        return $result;
    }

    private function thresholdFor(CarbonImmutable $localDate, mixed $expiresOn): ?int
    {
        if (! $expiresOn) {
            return null;
        }

        $expirationDate = CarbonImmutable::parse((string) $expiresOn, $this->timezone())->startOfDay();
        $days = $localDate->diffInDays($expirationDate, false);
        $wholeDays = (int) $days;

        return (float) $days === (float) $wholeDays && in_array($wholeDays, self::THRESHOLDS, true)
            ? $wholeDays
            : null;
    }

    private function deliver(ShopDocument $document, int $threshold): bool
    {
        $owner = $document->shopOwner;
        if (! $owner) {
            return false;
        }

        $expirationDate = $document->expires_on?->toDateString();
        if ($expirationDate === null) {
            return false;
        }

        $identity = (string) $document->version_number.'|'.$expirationDate;

        try {
            return (bool) DB::transaction(function () use ($document, $owner, $threshold, $identity, $expirationDate): bool {
                $alreadyDelivered = ShopDocumentReminderDelivery::query()
                    ->where('shop_document_id', $document->getKey())
                    ->where('expiration_identity', $identity)
                    ->where('threshold_days', $threshold)
                    ->where('recipient_type', 'shop_owner')
                    ->where('recipient_id', $owner->getKey())
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyDelivered) {
                    return false;
                }

                $notification = Notification::query()->create([
                    'shop_owner_id' => $owner->getKey(),
                    'type' => NotificationType::SHOP_DOCUMENT_EXPIRING->value,
                    'priority' => $threshold === 0 ? 'high' : 'medium',
                    'title' => $threshold === 0 ? 'Business document expires today' : 'Business document expiring soon',
                    'message' => $owner->business_name.' '.$document->logical_slot.' expires on '.$expirationDate.'.',
                    'data' => [
                        'document_id' => (int) $document->getKey(),
                        'shop_owner_id' => (int) $owner->getKey(),
                        'logical_slot' => (string) $document->logical_slot,
                        'version_number' => (int) $document->version_number,
                        'expires_on' => $expirationDate,
                        'threshold_days' => $threshold,
                    ],
                    'action_url' => '/shop-owner/settings',
                    'is_read' => false,
                    'requires_action' => true,
                    'is_archived' => false,
                ]);

                ShopDocumentReminderDelivery::query()->create([
                    'shop_document_id' => $document->getKey(),
                    'expiration_identity' => $identity,
                    'threshold_days' => $threshold,
                    'recipient_type' => 'shop_owner',
                    'recipient_id' => $owner->getKey(),
                    'notification_id' => $notification->getKey(),
                ]);

                return true;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateDelivery($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function isDuplicateDelivery(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'shop_doc_reminder_delivery_unique')
            || (str_contains($message, 'shop_document_reminder_deliveries') && str_contains($message, 'unique'));
    }

    private function timezone(): string
    {
        return (string) config('app.shop_timezone', 'Asia/Manila');
    }
}
