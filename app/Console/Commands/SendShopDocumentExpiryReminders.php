<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ShopDocumentReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SendShopDocumentExpiryReminders extends Command
{
    protected $signature = 'shop-documents:send-expiry-reminders
        {--date= : Local business date in YYYY-MM-DD format}
        {--shop-owner-id= : Limit processing to one shop owner ID}
        {--chunk=100 : Maximum rows processed per chunk, capped at 1000}';

    protected $description = 'Send deterministic shop-owner business-document expiry reminders.';

    public function handle(ShopDocumentReminderService $reminders): int
    {
        $timezone = (string) config('app.shop_timezone', 'Asia/Manila');
        $date = $this->dateOption($timezone);
        if ($date === null) {
            return self::FAILURE;
        }

        $shopOwnerId = $this->positiveIntegerOption('shop-owner-id');
        if ($this->option('shop-owner-id') !== null && $shopOwnerId === null) {
            $this->error('The shop owner ID must be a positive integer.');

            return self::FAILURE;
        }

        $chunk = $this->boundedIntegerOption('chunk', 100, 1000);
        $result = $reminders->sendForDate($date, $shopOwnerId, $chunk);

        $this->info(sprintf(
            'Processed %d candidates; sent %d reminders; skipped %d.',
            $result['matched'],
            $result['sent'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }

    private function dateOption(string $timezone): ?CarbonImmutable
    {
        $raw = $this->option('date');
        if ($raw === null || $raw === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $raw, $timezone);
        } catch (\Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== (string) $raw) {
            $this->error('The date must be a valid YYYY-MM-DD local business date.');

            return null;
        }

        return $date->startOfDay();
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    private function boundedIntegerOption(string $name, int $default, int $maximum): int
    {
        $raw = $this->option($name);
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        if ($value === false || $value < 1) {
            return $default;
        }

        return min((int) $value, $maximum);
    }
}
