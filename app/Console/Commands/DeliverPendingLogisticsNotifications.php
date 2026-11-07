<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeliverPendingLogisticsNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logistics:deliver-pending-notifications {--limit=100 : Maximum events processed per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry pending Logistics in-app notifications without replaying legacy history';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\Logistics\DeliveryEventService $events): int
    {
        $failed = 0;
        $ids = \App\Models\Logistics\DeliveryEvent::whereNotNull('notification_requested_at')->whereNull('notifications_processed_at')
            ->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            try {
                $events->deliverNotifications((int) $id);
            } catch (\Throwable) {
                $failed++;
            }
        }
        $this->info('Processed '.($ids->count() - $failed).' Logistics events; '.$failed.' pending failures.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
