<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

class KnownCredentialContainmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migrations_do_not_seed_a_privileged_account(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_01_15_110000_create_super_admins_table.php'));

        $this->assertDatabaseCount('super_admins', 0);
        $this->assertIsString($migration);
        $this->assertStringNotContainsString('admin@thesis.com', $migration);
        $this->assertStringNotContainsString('admin123', $migration);
        $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\Hash', $migration);
        $this->assertStringNotContainsString("DB::table('super_admins')", $migration);
    }

    public function test_command_accepts_only_a_non_secret_email_argument_and_uses_hidden_prompts(): void
    {
        $command = Artisan::all()['super-admin:rotate-compromised-credential'];
        $definition = $command->getDefinition();
        $source = file_get_contents(app_path('Console/Commands/RotateCompromisedSuperAdminCredential.php'));

        $this->assertTrue($definition->hasArgument('email'));
        $this->assertFalse($definition->hasOption('password'));
        $this->assertIsString($source);
        $this->assertSame(3, substr_count($source, '$this->secret('));
        $this->assertStringNotContainsString('$this->option(\'password\')', $source);
        $this->assertStringNotContainsString("where('user_id'", $source);
        $this->assertStringNotContainsString('where("user_id"', $source);
    }

    public function test_non_database_session_store_stops_before_prompt_or_mutation(): void
    {
        $legacyPassword = 'Legacy-Test-Pass1';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'password' => $legacyPassword,
        ]);
        $originalHash = $admin->getRawOriginal('password');

        config(['session.driver' => 'array']);

        $this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
            ->assertFailed()
            ->doesntExpectOutputToContain('Current password');

        $admin->refresh();
        $this->assertSame($originalHash, $admin->getRawOriginal('password'));
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_incorrect_current_password_does_not_mutate_or_audit(): void
    {
        $this->useDatabaseSessions();
        $legacyPassword = 'Legacy-Test-Pass1';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'password' => $legacyPassword,
            'remember_token' => 'remember-before',
        ]);
        $originalHash = $admin->getRawOriginal('password');
        $this->seedSessionRows($admin);

        $this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
            ->expectsQuestion('Current password', 'Wrong-Test-Pass1')
            ->assertFailed();

        $admin->refresh();
        $this->assertSame($originalHash, $admin->getRawOriginal('password'));
        $this->assertSame('remember-before', $admin->remember_token);
        $this->assertSame(2, DB::table(config('session.table'))->count());
        $this->assertSame(0, Activity::query()->where('event', 'super_admin_credential_rotated')->count());
    }

    public function test_only_an_active_super_admin_is_rotated_in_place(): void
    {
        $this->useDatabaseSessions();
        $legacyPassword = 'Legacy-Test-Pass1';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'password' => $legacyPassword,
            'status' => 'suspended',
            'remember_token' => 'remember-before',
        ]);
        $originalHash = $admin->getRawOriginal('password');

        $this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
            ->assertFailed();

        $admin->refresh();
        $this->assertSame($originalHash, $admin->getRawOriginal('password'));
        $this->assertSame('suspended', $admin->status);
        $this->assertSame('super_admin', $admin->role);
        $this->assertSame(1, SuperAdmin::query()->count());
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_valid_rotation_replaces_credentials_invalidates_all_sessions_and_audits_without_secrets(): void
    {
        $this->useDatabaseSessions();
        $legacyPassword = 'Legacy-Test-Pass1';
        $replacementPassword = 'Replacement-Test-Pass2';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'email' => 'Rotate.Me@Example.test',
            'password' => $legacyPassword,
            'remember_token' => 'remember-before',
            'status' => 'active',
        ]);
        $originalId = $admin->id;
        $this->seedSessionRows($admin);

        [$exitCode, $output] = $this->runInteractiveRotation(
            email: 'rotate.me@example.test',
            answers: [$legacyPassword, $replacementPassword, $replacementPassword],
        );

        $admin->refresh();
        $activity = Activity::query()
            ->where('event', 'super_admin_credential_rotated')
            ->latest('id')
            ->firstOrFail();
        $properties = $activity->properties->toArray();

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check($replacementPassword, $admin->password));
        $this->assertFalse(Hash::check($legacyPassword, $admin->password));
        $this->assertSame($originalId, $admin->id);
        $this->assertSame('super_admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertNotSame('remember-before', $admin->remember_token);
        $this->assertSame(1, SuperAdmin::query()->count());
        $this->assertSame(0, DB::table(config('session.table'))->count());

        $this->assertSame($admin->id, $activity->subject_id);
        $this->assertSame('console', $properties['source']);
        $this->assertNull($properties['ip_address']);
        $this->assertTrue(Str::isUuid($properties['correlation_id']));
        $this->assertStringContainsString($properties['correlation_id'], $output);
        $this->assertStringNotContainsString($legacyPassword, $output);
        $this->assertStringNotContainsString($replacementPassword, $output);
        $this->assertStringNotContainsString($legacyPassword, $activity->properties->toJson());
        $this->assertStringNotContainsString($replacementPassword, $activity->properties->toJson());
        $this->assertArrayNotHasKey('password', $properties);
        $this->assertArrayNotHasKey('current_password', $properties);
        $this->assertArrayNotHasKey('new_password', $properties);
        $this->assertArrayNotHasKey('remember_token', $properties);
    }

    public function test_audit_failure_rolls_back_password_remember_token_and_session_invalidation(): void
    {
        $this->useDatabaseSessions();
        $legacyPassword = 'Legacy-Test-Pass1';
        $replacementPassword = 'Replacement-Test-Pass2';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'password' => $legacyPassword,
            'remember_token' => 'remember-before',
        ]);
        $originalHash = $admin->getRawOriginal('password');
        $this->seedSessionRows($admin);

        $this->mock(PrivilegedAudit::class, function ($mock): void {
            $mock->shouldReceive('credentialRotatedByConsole')
                ->once()
                ->andThrow(new \RuntimeException('audit unavailable'));
        });

        $this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
            ->expectsQuestion('Current password', $legacyPassword)
            ->expectsQuestion('New password', $replacementPassword)
            ->expectsQuestion('Confirm new password', $replacementPassword)
            ->assertFailed();

        $admin->refresh();
        $this->assertSame($originalHash, $admin->getRawOriginal('password'));
        $this->assertSame('remember-before', $admin->remember_token);
        $this->assertSame(2, DB::table(config('session.table'))->count());
        $this->assertSame(0, Activity::query()->where('event', 'super_admin_credential_rotated')->count());
    }

    public function test_replacement_password_must_be_confirmed_strong_and_not_reused(): void
    {
        $this->useDatabaseSessions();
        $legacyPassword = 'Legacy-Test-Pass1';
        $admin = SuperAdmin::factory()->superAdmin()->create([
            'password' => $legacyPassword,
        ]);
        $originalHash = $admin->getRawOriginal('password');

        $this->artisan('super-admin:rotate-compromised-credential', ['email' => $admin->email])
            ->expectsQuestion('Current password', $legacyPassword)
            ->expectsQuestion('New password', $legacyPassword)
            ->expectsQuestion('Confirm new password', $legacyPassword)
            ->assertFailed();

        $admin->refresh();
        $this->assertSame($originalHash, $admin->getRawOriginal('password'));
        $this->assertSame(0, Activity::query()->where('event', 'super_admin_credential_rotated')->count());
    }

    private function useDatabaseSessions(): void
    {
        config([
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'session.connection' => null,
        ]);
    }

    private function seedSessionRows(SuperAdmin $admin): void
    {
        DB::table(config('session.table'))->insert([
            [
                'id' => 'super-admin-session',
                'user_id' => $admin->id,
                'ip_address' => '203.0.113.7',
                'user_agent' => 'test-agent',
                'payload' => 'payload-one',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'unrelated-session',
                'user_id' => 999999,
                'ip_address' => '198.51.100.4',
                'user_agent' => 'test-agent',
                'payload' => 'payload-two',
                'last_activity' => now()->timestamp,
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $answers
     * @return array{0:int,1:string}
     */
    private function runInteractiveRotation(string $email, array $answers): array
    {
        $buffer = new BufferedOutput;
        $input = new ArrayInput([]);
        $output = new class($input, $buffer, $answers) extends \Illuminate\Console\OutputStyle
        {
            /** @var array<int, string> */
            private array $answers;

            /**
             * @param  array<int, string>  $answers
             */
            public function __construct(
                InputInterface $input,
                OutputInterface $output,
                array $answers,
            ) {
                parent::__construct($input, $output);
                $this->answers = $answers;
            }

            public function askQuestion(Question $question): mixed
            {
                return array_shift($this->answers);
            }
        };

        $exitCode = $this->app->make(Kernel::class)->call(
            'super-admin:rotate-compromised-credential',
            ['email' => $email],
            $output,
        );

        return [$exitCode, $buffer->fetch()];
    }
}
