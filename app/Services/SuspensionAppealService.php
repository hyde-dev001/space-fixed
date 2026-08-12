<?php

namespace App\Services;

use App\Mail\SuspensionAppealDecisionMail;
use App\Mail\SuspensionAppealSubmittedMail;
use App\Mail\SuspensionNoticeMail;
use App\Models\AccountSuspension;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SuspensionAppealService
{
    public function createForSuspension(
        User|ShopOwner $account,
        AccountSuspension $suspension,
        string $reason,
        ?int $suspendedBySuperAdminId,
    ): SuspensionAppeal {
        $accountType = $account instanceof ShopOwner
            ? AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
            : AccountSuspension::ACCOUNT_TYPE_CUSTOMER;
        $expectedName = $account instanceof ShopOwner
            ? trim(($account->business_name ?? '') ?: (($account->first_name ?? '') . ' ' . ($account->last_name ?? '')))
            : trim((string) ($account->name ?? (($account->first_name ?? '') . ' ' . ($account->last_name ?? ''))));
        $recipientEmail = trim((string) ($account->email ?? ''));

        if ($recipientEmail === '') {
            throw new \RuntimeException('A suspension appeal recipient email is required.');
        }

        SuspensionAppeal::query()
            ->where('account_type', $accountType)
            ->where('account_id', (int) $account->getKey())
            ->where(function ($query) use ($suspension): void {
                $query->whereNull('suspension_id')
                    ->orWhere('suspension_id', '!=', (int) $suspension->getKey());
            })
            ->whereIn('status', ['eligible', 'submitted'])
            ->lockForUpdate()
            ->get()
            ->each(function (SuspensionAppeal $appeal): void {
                $appeal->forceFill([
                    'status' => 'superseded',
                    'reviewed_at' => now(),
                ])->save();
            });

        return SuspensionAppeal::query()->create([
            'account_type' => $accountType,
            'account_id' => (int) $account->getKey(),
            'suspension_id' => (int) $suspension->getKey(),
            'account_name' => $expectedName,
            'recipient_email' => $recipientEmail,
            'suspension_reason' => trim($reason),
            'suspended_by_super_admin_id' => $suspendedBySuperAdminId,
            'status' => 'eligible',
            'appeal_token' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168)),
        ]);
    }

    public function sendSuspensionNotice(SuspensionAppeal $appeal): void
    {
        if ((string) $appeal->status !== 'eligible') {
            return;
        }

        $recipientEmail = trim((string) $appeal->recipient_email);
        if ($recipientEmail === '') {
            return;
        }

        $expiresAt = $appeal->expires_at ?? now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168));
        $appealUrl = URL::temporarySignedRoute(
            'appeals.show',
            $expiresAt,
            ['token' => $appeal->appeal_token]
        );
        $accountTypeLabel = $appeal->account_type === AccountSuspension::ACCOUNT_TYPE_SHOP_OWNER
            ? 'shop owner'
            : 'customer';

        try {
            Mail::to($recipientEmail)->send(new SuspensionNoticeMail(
                accountName: (string) ($appeal->account_name !== '' ? $appeal->account_name : 'User'),
                accountTypeLabel: $accountTypeLabel,
                reason: $appeal->suspension_reason,
                appealUrl: $appealUrl,
                expiresAtLabel: $expiresAt->format('M d, Y h:i A')
            ));

            Log::info('Suspension notice email sent', [
                'appeal_id' => $appeal->id,
                'email' => $recipientEmail,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send suspension notice email', [
                'appeal_id' => $appeal->id,
                'email' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createAndSendForShopOwner(ShopOwner $shopOwner, ?string $reason, ?int $suspendedBySuperAdminId): ?SuspensionAppeal
    {
        $email = trim((string) ($shopOwner->email ?? ''));
        if ($email === '') {
            return null;
        }

        $name = trim(($shopOwner->business_name ?? '') ?: (($shopOwner->first_name ?? '') . ' ' . ($shopOwner->last_name ?? '')));

        return $this->createAndSend(
            accountType: 'shop_owner',
            accountId: (int) $shopOwner->id,
            accountName: $name,
            recipientEmail: $email,
            reason: $reason,
            suspendedBySuperAdminId: $suspendedBySuperAdminId,
            accountTypeLabel: 'shop owner'
        );
    }

    public function createAndSendForCustomer(User $user, ?string $reason, ?int $suspendedBySuperAdminId): ?SuspensionAppeal
    {
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return null;
        }

        $name = trim((string) ($user->name ?? (($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));

        return $this->createAndSend(
            accountType: 'customer',
            accountId: (int) $user->id,
            accountName: $name,
            recipientEmail: $email,
            reason: $reason,
            suspendedBySuperAdminId: $suspendedBySuperAdminId,
            accountTypeLabel: 'customer'
        );
    }

    public function sendDecisionEmail(SuspensionAppeal $appeal): void
    {
        $decision = (string) $appeal->status;
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return;
        }

        $email = trim((string) $appeal->recipient_email);
        if ($email === '') {
            return;
        }

        $typeLabel = $appeal->account_type === 'shop_owner' ? 'shop owner' : 'customer';

        try {
            Mail::to($email)->send(new SuspensionAppealDecisionMail(
                accountName: (string) ($appeal->account_name ?: 'User'),
                accountTypeLabel: $typeLabel,
                decision: $decision,
                reviewerNotes: $appeal->reviewer_notes
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send suspension appeal decision email', [
                'appeal_id' => $appeal->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendSubmissionNotificationToSuperAdmins(SuspensionAppeal $appeal): void
    {
        if ((string) $appeal->status !== 'submitted') {
            return;
        }

        $appealMessage = trim((string) ($appeal->appeal_message ?? ''));
        if ($appealMessage === '') {
            return;
        }

        $recipientEmails = SuperAdmin::query()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => $email !== '')
            ->unique()
            ->values();

        if ($recipientEmails->isEmpty()) {
            return;
        }

        $typeLabel = $appeal->account_type === 'shop_owner' ? 'shop owner' : 'customer';
        $reviewUrl = route('admin.suspension-appeals');
        $submittedAtLabel = ($appeal->submitted_at ?? now())->format('M d, Y h:i A');

        foreach ($recipientEmails as $email) {
            try {
                Mail::to($email)->send(new SuspensionAppealSubmittedMail(
                    accountName: (string) ($appeal->account_name ?: 'User'),
                    accountTypeLabel: $typeLabel,
                    recipientEmail: (string) ($appeal->recipient_email ?? ''),
                    suspensionReason: $appeal->suspension_reason,
                    appealMessage: $appealMessage,
                    submittedAtLabel: $submittedAtLabel,
                    reviewUrl: $reviewUrl
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to send suspension appeal submitted email', [
                    'appeal_id' => $appeal->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function createAndSend(
        string $accountType,
        int $accountId,
        string $accountName,
        string $recipientEmail,
        ?string $reason,
        ?int $suspendedBySuperAdminId,
        string $accountTypeLabel
    ): SuspensionAppeal {
        $expiresAt = now()->addHours((int) config('reporting.suspension_appeal_link_hours', 168));

        SuspensionAppeal::query()
            ->where('account_type', $accountType)
            ->where('account_id', $accountId)
            ->whereIn('status', ['eligible', 'submitted'])
            ->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

        $appeal = SuspensionAppeal::create([
            'account_type' => $accountType,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'recipient_email' => $recipientEmail,
            'suspension_reason' => $reason,
            'suspended_by_super_admin_id' => $suspendedBySuperAdminId,
            'status' => 'eligible',
            'appeal_token' => hash('sha256', (string) Str::uuid()),
            'expires_at' => $expiresAt,
        ]);

        $this->sendSuspensionNotice($appeal);

        return $appeal;
    }
}
