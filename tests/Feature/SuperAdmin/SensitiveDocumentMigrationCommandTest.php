<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SensitiveDocumentMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_dry_run_reports_both_record_types_without_changing_files_or_metadata(): void
    {
        $document = $this->createDocument('public', 'shop_documents/dry-run.pdf');
        $customer = $this->createCustomer('public', 'valid_ids/dry-run.jpg');
        Storage::disk('public')->put($document->file_path, 'public-document');
        Storage::disk('public')->put($customer->valid_id_path, 'public-id');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('security:migrate-sensitive-documents-private', [
            '--dry-run' => true,
            '--chunk' => 1,
        ], $output);
        $this->assertSame(0, $exitCode);
        $commandOutput = $output->fetch();
        $this->assertStringContainsString('Shop documents:', $commandOutput);
        $this->assertStringContainsString('would_migrate=1', $commandOutput);
        $this->assertStringContainsString('Customer valid IDs:', $commandOutput);
        $this->assertStringContainsString('Totals:', $commandOutput);

        Storage::disk('public')->assertExists($document->file_path);
        Storage::disk('public')->assertExists($customer->valid_id_path);
        Storage::disk('local')->assertMissing($document->file_path);
        Storage::disk('local')->assertMissing($customer->valid_id_path);
        $this->assertSame('public', $document->fresh()->disk);
        $this->assertSame('public', $customer->fresh()->valid_id_disk);
    }

    public function test_migration_copies_verifies_switches_metadata_and_removes_public_sources(): void
    {
        $document = $this->createDocument('public', 'shop_documents/migrate.pdf');
        $customer = $this->createCustomer('public', 'valid_ids/migrate.jpg');
        Storage::disk('public')->put($document->file_path, 'document-bytes');
        Storage::disk('public')->put($customer->valid_id_path, 'customer-id-bytes');

        $this->artisan('security:migrate-sensitive-documents-private', ['--chunk' => 1])
            ->expectsOutputToContain('migrated=1')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('local')->assertExists($customer->valid_id_path);
        Storage::disk('public')->assertMissing($document->file_path);
        Storage::disk('public')->assertMissing($customer->valid_id_path);
        $this->assertSame('document-bytes', Storage::disk('local')->get($document->file_path));
        $this->assertSame('customer-id-bytes', Storage::disk('local')->get($customer->valid_id_path));
        $this->assertSame('local', $document->fresh()->disk);
        $this->assertSame('local', $customer->fresh()->valid_id_disk);
    }

    public function test_rerunning_after_success_reports_already_private_and_succeeds(): void
    {
        $document = $this->createDocument('public', 'shop_documents/rerun.pdf');
        Storage::disk('public')->put($document->file_path, 'document-bytes');

        $this->artisan('security:migrate-sensitive-documents-private')->assertSuccessful();

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('already_private=1')
            ->assertSuccessful();
    }

    public function test_matching_public_duplicates_are_verified_and_removed(): void
    {
        $document = $this->createDocument('local', 'shop_documents/duplicate.pdf');
        Storage::disk('local')->put($document->file_path, 'same-bytes');
        Storage::disk('public')->put($document->file_path, 'same-bytes');

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('duplicates_removed=1')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
        $this->assertSame('local', $document->fresh()->disk);
    }

    public function test_conflicting_private_and_public_bytes_are_preserved_and_fail(): void
    {
        $document = $this->createDocument('local', 'shop_documents/conflict.pdf');
        $customer = $this->createCustomer('local', 'valid_ids/conflict.jpg');
        Storage::disk('local')->put($document->file_path, 'private-document');
        Storage::disk('public')->put($document->file_path, 'public-document');
        Storage::disk('local')->put($customer->valid_id_path, 'private-id');
        Storage::disk('public')->put($customer->valid_id_path, 'public-id');

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('conflicts=1')
            ->assertFailed();

        $this->assertSame('private-document', Storage::disk('local')->get($document->file_path));
        $this->assertSame('public-document', Storage::disk('public')->get($document->file_path));
        $this->assertSame('private-id', Storage::disk('local')->get($customer->valid_id_path));
        $this->assertSame('public-id', Storage::disk('public')->get($customer->valid_id_path));
        $this->assertSame('local', $document->fresh()->disk);
        $this->assertSame('local', $customer->fresh()->valid_id_disk);
    }

    public function test_missing_files_fail_without_guessing_metadata(): void
    {
        $document = $this->createDocument('public', 'shop_documents/missing.pdf');
        $customer = $this->createCustomer('public', 'valid_ids/missing.jpg');

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('missing=1')
            ->assertFailed();

        $this->assertSame('public', $document->fresh()->disk);
        $this->assertSame('public', $customer->fresh()->valid_id_disk);
        Storage::disk('local')->assertMissing($document->file_path);
        Storage::disk('local')->assertMissing($customer->valid_id_path);
    }

    public function test_private_write_or_checksum_failure_preserves_public_source_and_metadata(): void
    {
        $document = $this->createDocument('public', 'shop_documents/write-failure.pdf');
        $public = Mockery::mock();
        $private = Mockery::mock();

        Storage::shouldReceive('disk')->with('public')->andReturn($public);
        Storage::shouldReceive('disk')->with('local')->andReturn($private);
        $public->shouldReceive('exists')->with($document->file_path)->andReturnTrue();
        $private->shouldReceive('exists')->with($document->file_path)->andReturnFalse();
        $public->shouldReceive('get')->with($document->file_path)->andReturn('public-bytes');
        $private->shouldReceive('put')->with($document->file_path, 'public-bytes')->andReturnTrue();
        $private->shouldReceive('get')->with($document->file_path)->andReturn('corrupt-bytes');
        $private->shouldReceive('delete')->with($document->file_path)->once()->andReturnTrue();
        $public->shouldNotReceive('delete');

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('failed=1')
            ->assertFailed();

        $this->assertSame('public', $document->fresh()->disk);
    }

    public function test_metadata_update_failure_preserves_public_source_and_metadata(): void
    {
        $document = $this->createDocument('public', 'shop_documents/metadata-failure.pdf');
        Storage::disk('public')->put($document->file_path, 'public-bytes');
        $shouldFail = true;

        DB::listen(function (QueryExecuted $query) use (&$shouldFail): void {
            if ($shouldFail
                && str_contains(strtolower($query->sql), 'update')
                && str_contains(strtolower($query->sql), 'shop_documents')
                && str_contains(strtolower($query->sql), 'disk')) {
                $shouldFail = false;
                throw new \RuntimeException('forced metadata update failure');
            }
        });

        $this->artisan('security:migrate-sensitive-documents-private')
            ->expectsOutputToContain('failed=1')
            ->assertFailed();

        Storage::disk('public')->assertExists($document->file_path);
        Storage::disk('local')->assertMissing($document->file_path);
        $this->assertSame('public', $document->fresh()->disk);
    }

    public function test_restore_public_copies_and_switches_metadata_while_retaining_private_copy(): void
    {
        $document = $this->createDocument('local', 'shop_documents/restore.pdf');
        $customer = $this->createCustomer('local', 'valid_ids/restore.jpg');
        Storage::disk('local')->put($document->file_path, 'private-document');
        Storage::disk('local')->put($customer->valid_id_path, 'private-id');

        $this->artisan('security:migrate-sensitive-documents-private', ['--restore-public' => true])
            ->expectsOutputToContain('restored=1')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($document->file_path);
        Storage::disk('public')->assertExists($document->file_path);
        Storage::disk('local')->assertExists($customer->valid_id_path);
        Storage::disk('public')->assertExists($customer->valid_id_path);
        $this->assertSame('private-document', Storage::disk('public')->get($document->file_path));
        $this->assertSame('private-id', Storage::disk('public')->get($customer->valid_id_path));
        $this->assertSame('public', $document->fresh()->disk);
        $this->assertSame('public', $customer->fresh()->valid_id_disk);
    }

    public function test_chunk_one_processes_records_incrementally_and_is_resumable(): void
    {
        $documents = collect(range(1, 3))->map(function (int $number): ShopDocument {
            $document = $this->createDocument('public', "shop_documents/chunk-{$number}.pdf");
            Storage::disk('public')->put($document->file_path, "document-{$number}");

            return $document;
        });

        $this->artisan('security:migrate-sensitive-documents-private', ['--chunk' => 1])
            ->expectsOutputToContain('migrated=3')
            ->assertSuccessful();

        $documents->each(function (ShopDocument $document): void {
            Storage::disk('local')->assertExists($document->file_path);
            Storage::disk('public')->assertMissing($document->file_path);
        });

        $this->artisan('security:migrate-sensitive-documents-private', ['--chunk' => 1])
            ->expectsOutputToContain('already_private=3')
            ->assertSuccessful();
    }

    private function createDocument(string $disk, string $path): ShopDocument
    {
        $shopOwner = ShopOwner::factory()->create();
        $document = ShopDocument::create([
            'shop_owner_id' => $shopOwner->id,
            'document_type' => 'mayors_permit',
            'file_path' => $path,
            'status' => 'pending',
        ]);
        $document->disk = $disk;
        $document->save();

        return $document;
    }

    private function createCustomer(string $disk, string $path): User
    {
        $customer = User::factory()->create([
            'valid_id_path' => $path,
        ]);
        $customer->valid_id_disk = $disk;
        $customer->save();

        return $customer;
    }
}
