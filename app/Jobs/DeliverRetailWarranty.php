<?php

namespace App\Jobs;

use App\Mail\RetailWarrantyMail;
use App\Models\RetailWarrantyIssuance;
use App\Services\RetailWarrantyCertificateService;
use App\Services\RetailWarrantyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class DeliverRetailWarranty implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $issuanceId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'retail-warranty:'.$this->issuanceId;
    }

    public function handle(RetailWarrantyCertificateService $certificates): void
    {
        $issuance = RetailWarrantyIssuance::findOrFail($this->issuanceId);
        if (! in_array($issuance->email_delivery_state, ['pending', 'failed'], true)) {
            return;
        }
        try {
            $certificates->generate($issuance);
            $issuance = $issuance->fresh()->load('warranties');
            $recipient = $issuance->customer_snapshot['email'] ?? null;
            $mail = new RetailWarrantyMail($issuance);
            if ($recipient && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->render(); // Definite template failure remains safely retryable before transport claim.
                $pendingMail = Mail::to($recipient);
            }
        } catch (Throwable $failure) {
            $this->recordPreSendFailure();
            throw $failure;
        }
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($recipient, $token) {
            $locked = RetailWarrantyIssuance::whereKey($this->issuanceId)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->email_delivery_state, ['pending', 'failed'], true)) {
                return false;
            }
            if (! $recipient || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $locked->forceFill(['email_delivery_state' => 'skipped', 'email_failure_code' => 'no_valid_recipient'])->save();
                app(RetailWarrantyService::class)->audit($locked->shop_owner_id, 'retail_warranty.email_skipped', 'retail_warranty_issuance', $locked->id);

                return false;
            }
            $locked->forceFill(['email_delivery_state' => 'sending', 'email_attempt_token' => $token,
                'email_attempted_at' => now()->utc(), 'email_attempts' => $locked->email_attempts + 1, 'email_failure_code' => null])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            $sent = $pendingMail->send($mail);
            if (! $sent) {
                throw new \RuntimeException('Mail acceptance was not confirmed.');
            }
            DB::transaction(function () use ($sent, $token) {
                $locked = RetailWarrantyIssuance::whereKey($this->issuanceId)->lockForUpdate()->firstOrFail();
                if ($locked->email_attempt_token !== $token || $locked->email_delivery_state !== 'sending') {
                    return;
                }
                $locked->forceFill(['email_delivery_state' => 'sent', 'email_sent_at' => now()->utc(), 'provider_reference' => $sent->getMessageId()])->save();
                app(RetailWarrantyService::class)->audit($locked->shop_owner_id, 'retail_warranty.email_sent', 'retail_warranty_issuance', $locked->id);
            });
        } catch (Throwable $failure) {
            DB::transaction(function () use ($token) {
                $locked = RetailWarrantyIssuance::whereKey($this->issuanceId)->lockForUpdate()->firstOrFail();
                if ($locked->email_attempt_token === $token && $locked->email_delivery_state === 'sending') {
                    $locked->forceFill(['email_delivery_state' => 'unknown', 'email_failure_code' => 'acceptance_unknown'])->save();
                    app(RetailWarrantyService::class)->audit($locked->shop_owner_id, 'retail_warranty.email_unknown', 'retail_warranty_issuance', $locked->id);
                }
            });
            Log::warning('Product Warranty email acceptance unknown.', ['issuance_id' => $this->issuanceId]);
        }
    }

    private function recordPreSendFailure(): void
    {
        DB::transaction(function () {
            $locked = RetailWarrantyIssuance::whereKey($this->issuanceId)->lockForUpdate()->firstOrFail();
            if (in_array($locked->email_delivery_state, ['pending', 'failed'], true)) {
                $locked->forceFill(['email_delivery_state' => 'failed', 'email_attempted_at' => now()->utc(),
                    'email_attempts' => $locked->email_attempts + 1, 'email_failure_code' => 'pre_send_failure'])->save();
                app(RetailWarrantyService::class)->audit($locked->shop_owner_id, 'retail_warranty.email_failed', 'retail_warranty_issuance', $locked->id, ['code' => 'pre_send_failure']);
            }
        });
    }

    public function failed(?Throwable $failure): void
    {
        Log::warning('Product Warranty delivery job failed.', ['issuance_id' => $this->issuanceId]);
    }
}
