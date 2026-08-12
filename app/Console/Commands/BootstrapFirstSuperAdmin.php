<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PrivilegedDeliveryType;
use App\Models\PrivilegedSecurityToken;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use App\Services\PrivilegedMailDispatcher;
use App\Services\PrivilegedSecurityTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class BootstrapFirstSuperAdmin extends Command
{
    protected $signature = 'super-admin:bootstrap';

    protected $description = 'Create the first pending Super Admin through interactive setup';

    public function __construct(
        private readonly PrivilegedAudit $audit,
        private readonly PrivilegedSecurityTokenService $tokens,
        private readonly PrivilegedMailDispatcher $privilegedMailDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Bootstrap failed: interactive prompts are required.');

            return self::FAILURE;
        }

        $existing = SuperAdmin::query()->orderBy('id')->get();
        if ($existing->isNotEmpty()) {
            $pending = $existing->count() === 1 ? $existing->first() : null;

            if (! $pending instanceof SuperAdmin
                || $pending->status !== SuperAdmin::STATUS_PENDING_SETUP
                || $pending->bootstrap_marker !== 'platform'
                || SuperAdmin::query()->where('status', SuperAdmin::STATUS_ACTIVE)->exists()
                || ! $this->confirm('Replace the pending platform setup account?', false)) {
                $this->error('Bootstrap refused: a privileged account already exists.');

                return self::FAILURE;
            }

            return $this->replacePendingAccount($pending);
        }

        $identity = $this->promptIdentity();
        if ($identity === null) {
            return self::FAILURE;
        }

        $correlationId = (string) Str::uuid();

        try {
            /** @var array{admin: SuperAdmin} $result */
            $result = DB::transaction(function () use ($identity, $correlationId): array {
                $admin = new SuperAdmin([
                    'first_name' => $identity['first_name'],
                    'last_name' => $identity['last_name'],
                    'email' => $identity['email'],
                    'phone' => $identity['phone'],
                    'password' => Str::random(64),
                    'role' => SuperAdmin::ROLE_SUPER_ADMIN,
                    'status' => SuperAdmin::STATUS_PENDING_SETUP,
                ]);
                $admin->forceFill(['bootstrap_marker' => 'platform'])->save();
                $issued = $this->tokens->issue($admin, PrivilegedSecurityToken::PURPOSE_SETUP, null);
                $this->audit->privilegedBootstrapCreated($admin, $correlationId);

                $this->privilegedMailDispatcher->dispatch(
                    type: PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP,
                    businessEventId: 'privileged-admin-setup:'.$admin->getKey().':'.$issued['token']->getKey(),
                    recipientType: 'super_admin',
                    recipientId: (int) $admin->getKey(),
                    payload: [
                        'recipient_name' => trim($admin->first_name.' '.$admin->last_name),
                        'email' => (string) $admin->email,
                        'raw_token' => $issued['raw_token'],
                    ],
                    correlationId: $correlationId,
                );

                return [
                    'admin' => $admin,
                ];
            });
        } catch (Throwable) {
            $this->error('Bootstrap failed; no account or setup token was committed.');

            return self::FAILURE;
        }

        $this->printSuccess($result['admin'], $correlationId);

        return self::SUCCESS;
    }

    /** @return array{first_name: string, last_name: string, email: string, phone: string}|null */
    private function promptIdentity(): ?array
    {
        $input = [
            'first_name' => trim((string) $this->ask('First name')),
            'last_name' => trim((string) $this->ask('Last name')),
            'email' => Str::lower(trim((string) $this->ask('Email'))),
            'phone' => trim((string) $this->ask('Phone')),
        ];
        $validator = Validator::make($input, [
            'first_name' => ['required', 'string', 'min:2', 'max:255'],
            'last_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:super_admins,email'],
            'phone' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        if ($validator->fails()) {
            $this->error('Bootstrap failed: the supplied identity details are invalid.');

            return null;
        }

        return $input;
    }

    private function replacePendingAccount(SuperAdmin $pending): int
    {
        $correlationId = (string) Str::uuid();

        try {
            /** @var array{admin: SuperAdmin} $result */
            $result = DB::transaction(function () use ($pending, $correlationId): array {
                $locked = SuperAdmin::query()->lockForUpdate()->find($pending->getKey());

                if (! $locked instanceof SuperAdmin
                    || $locked->status !== SuperAdmin::STATUS_PENDING_SETUP
                    || $locked->bootstrap_marker !== 'platform') {
                    throw new \RuntimeException('The pending bootstrap account is no longer replaceable.');
                }

                $issued = $this->tokens->issue($locked, PrivilegedSecurityToken::PURPOSE_SETUP, null);
                $this->audit->privilegedBootstrapCreated($locked, $correlationId);

                $this->privilegedMailDispatcher->dispatch(
                    type: PrivilegedDeliveryType::PRIVILEGED_ADMIN_SETUP,
                    businessEventId: 'privileged-admin-setup:'.$locked->getKey().':'.$issued['token']->getKey(),
                    recipientType: 'super_admin',
                    recipientId: (int) $locked->getKey(),
                    payload: [
                        'recipient_name' => trim($locked->first_name.' '.$locked->last_name),
                        'email' => (string) $locked->email,
                        'raw_token' => $issued['raw_token'],
                    ],
                    correlationId: $correlationId,
                );

                return [
                    'admin' => $locked,
                ];
            });
        } catch (Throwable) {
            $this->error('Bootstrap replacement failed; the existing pending account was preserved.');

            return self::FAILURE;
        }

        $this->printSuccess($result['admin'], $correlationId);

        return self::SUCCESS;
    }

    private function printSuccess(SuperAdmin $admin, string $correlationId): void
    {
        $this->info(sprintf(
            'Created pending Super Admin #%d (%s). Operation ID: %s',
            $admin->getKey(),
            $admin->email,
            $correlationId,
        ));
    }
}
