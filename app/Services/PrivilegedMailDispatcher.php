<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PrivilegedDeliveryType;
use App\Jobs\SendPrivilegedWorkflowMail;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrivilegedMailDispatcher
{
    public function __construct(private readonly Dispatcher $dispatcher)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        PrivilegedDeliveryType $type,
        string $businessEventId,
        string $recipientType,
        int $recipientId,
        string $channel = 'mail',
        array $payload = [],
        ?string $correlationId = null,
        ?string $requiredCapability = null,
    ): void {
        $job = new SendPrivilegedWorkflowMail(
            deliveryType: $type,
            businessEventId: $businessEventId,
            recipientType: $recipientType,
            recipientId: $recipientId,
            channel: $channel,
            payload: $payload,
            correlationId: $correlationId,
            requiredCapability: $requiredCapability,
        );

        DB::afterCommit(function () use ($job): void {
            try {
                $this->dispatcher->dispatch($job);
            } catch (Throwable $exception) {
                Log::error('Privileged workflow delivery enqueue failed after commit.', $job->safeContext());
            }
        });
    }
}
