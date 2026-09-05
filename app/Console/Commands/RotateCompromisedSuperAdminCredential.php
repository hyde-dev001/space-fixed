<?php

namespace App\Console\Commands;

use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class RotateCompromisedSuperAdminCredential extends Command
{
    protected $signature = 'super-admin:rotate-compromised-credential {email : Existing Super Admin email}';

    protected $description = 'Rotate one active Super Admin credential and invalidate all database sessions';

    public function __construct(private readonly PrivilegedAudit $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Credential rotation failed: provide a valid Super Admin email.');

            return self::FAILURE;
        }

        $admin = $this->findUniqueAdmin($email);

        if (! $admin instanceof SuperAdmin) {
            $this->error('Credential rotation failed: exactly one matching Super Admin is required.');

            return self::FAILURE;
        }

        if (! $admin->isActive()) {
            $this->error('Credential rotation failed: the matching Super Admin is not active.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Credential rotation failed: interactive hidden prompts are required.');

            return self::FAILURE;
        }

        if (! $this->sessionStoreIsReady()) {
            return self::FAILURE;
        }

        try {
            $currentPassword = $this->secret('Current password');
        } catch (Throwable) {
            $this->error('Credential rotation failed: hidden prompts could not be answered.');

            return self::FAILURE;
        }

        if (! is_string($currentPassword)) {
            $this->error('Credential rotation failed: hidden prompts could not be answered.');

            return self::FAILURE;
        }

        if (! Hash::check($currentPassword, (string) $admin->password)) {
            $this->error('Credential rotation failed: the current password is incorrect.');

            return self::FAILURE;
        }

        try {
            $newPassword = $this->secret('New password');
            $confirmedPassword = $this->secret('Confirm new password');
        } catch (Throwable) {
            $this->error('Credential rotation failed: hidden prompts could not be answered.');

            return self::FAILURE;
        }

        if (! is_string($newPassword) || ! is_string($confirmedPassword)) {
            $this->error('Credential rotation failed: hidden prompts could not be answered.');

            return self::FAILURE;
        }

        if ($newPassword !== $confirmedPassword) {
            $this->error('Credential rotation failed: the replacement passwords do not match.');

            return self::FAILURE;
        }

        $validation = Validator::make(
            ['password' => $newPassword],
            ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]],
        );

        if ($validation->fails()) {
            $this->error('Credential rotation failed: '.$validation->errors()->first('password'));

            return self::FAILURE;
        }

        if (Hash::check($newPassword, (string) $admin->password)) {
            $this->error('Credential rotation failed: the replacement password must be new.');

            return self::FAILURE;
        }

        $operationId = (string) Str::uuid();

        try {
            DB::transaction(function () use ($admin, $email, $currentPassword, $newPassword, $operationId): void {
                $lockedAdmin = SuperAdmin::query()
                    ->whereKey($admin->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedAdmin instanceof SuperAdmin
                    || ! $lockedAdmin->isActive()
                    || Str::lower(trim((string) $lockedAdmin->email)) !== $email
                    || ! Hash::check($currentPassword, (string) $lockedAdmin->password)) {
                    throw new \RuntimeException('The Super Admin changed before rotation completed.');
                }

                $lockedAdmin->forceFill([
                    'password' => $newPassword,
                    'remember_token' => Str::random(60),
                ])->save();

                $sessions = $this->sessionConnection()->table((string) config('session.table'));
                $sessions->delete();

                if ($sessions->count() !== 0) {
                    throw new \RuntimeException('Database sessions could not be invalidated.');
                }

                $this->audit->credentialRotatedByConsole($lockedAdmin, $operationId);
            });
        } catch (Throwable) {
            $this->error('Credential rotation failed; no changes were committed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Credential rotation succeeded for Super Admin #%d (%s). Operation ID: %s',
            $admin->getKey(),
            $admin->email,
            $operationId,
        ));

        return self::SUCCESS;
    }

    private function findUniqueAdmin(string $email): ?SuperAdmin
    {
        $admins = SuperAdmin::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->get();

        return $admins->count() === 1 ? $admins->first() : null;
    }

    private function sessionStoreIsReady(): bool
    {
        if (config('session.driver') !== 'database') {
            $this->error('Credential rotation failed: only the database session store is supported.');

            return false;
        }

        $table = config('session.table');

        if (! is_string($table) || $table === '') {
            $this->error('Credential rotation failed: the database session table is not configured.');

            return false;
        }

        $connection = $this->sessionConnection();

        $configuredConnection = config('session.connection');
        if (is_string($configuredConnection)
            && $configuredConnection !== ''
            && $configuredConnection !== (string) config('database.default')) {
            $this->error('Credential rotation failed: the session connection must match the application database.');

            return false;
        }

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            $this->error('Credential rotation failed: the configured database session table does not exist.');

            return false;
        }

        $probeId = 'super-admin-rotation-probe-'.Str::uuid();

        try {
            $connection->transaction(function () use ($connection, $table, $probeId): void {
                $connection->table($table)->insert([
                    'id' => $probeId,
                    'user_id' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'payload' => '',
                    'last_activity' => now()->timestamp,
                ]);

                $connection->table($table)->where('id', $probeId)->delete();
            });
        } catch (Throwable) {
            $this->error('Credential rotation failed: the configured database session table is not writable.');

            return false;
        }

        return true;
    }

    private function sessionConnection(): Connection
    {
        $connection = config('session.connection');

        return DB::connection(is_string($connection) && $connection !== ''
            ? $connection
            : (string) config('database.default'));
    }
}
