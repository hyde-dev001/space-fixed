<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->string('logical_slot', 120)->nullable()->after('document_type');
            $table->unsignedInteger('version_number')->nullable()->after('logical_slot');
            $table->foreignId('predecessor_document_id')
                ->nullable()
                ->after('version_number')
                ->constrained('shop_documents')
                ->nullOnDelete();
            $table->boolean('is_current')->nullable()->after('predecessor_document_id');
            $table->timestamp('superseded_at')->nullable()->after('is_current');
            $table->date('issued_on')->nullable()->after('superseded_at');
            $table->string('expiration_mode', 16)->nullable()->after('issued_on');
            $table->date('expires_on')->nullable()->after('expiration_mode');
            $table->foreignId('reviewed_by_super_admin_id')
                ->nullable()
                ->after('expires_on')
                ->constrained('super_admins')
                ->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_super_admin_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->uuid('submission_key')->nullable()->after('rejection_reason')->unique();
            $table->char('checksum_sha256', 64)->nullable()->after('submission_key');

            $table->unique(
                ['shop_owner_id', 'logical_slot', 'version_number'],
                'shop_doc_owner_slot_version_unique',
            );
            $table->index(
                ['shop_owner_id', 'logical_slot', 'is_current'],
                'shop_doc_owner_slot_current_idx',
            );
            $table->index(
                ['is_current', 'status', 'reviewed_at', 'expires_on'],
                'shop_doc_reminder_candidate_idx',
            );
        });

        $this->addSingleCurrentConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropSingleCurrentConstraint();

        Schema::table('shop_documents', function (Blueprint $table): void {
            $table->dropIndex('shop_doc_reminder_candidate_idx');
            $table->dropIndex('shop_doc_owner_slot_current_idx');
            $table->dropUnique('shop_doc_owner_slot_version_unique');
            $table->dropForeign(['predecessor_document_id']);
            $table->dropForeign(['reviewed_by_super_admin_id']);
            $table->dropUnique(['submission_key']);
            $table->dropColumn([
                'logical_slot',
                'version_number',
                'predecessor_document_id',
                'is_current',
                'superseded_at',
                'issued_on',
                'expiration_mode',
                'expires_on',
                'reviewed_by_super_admin_id',
                'reviewed_at',
                'rejection_reason',
                'submission_key',
                'checksum_sha256',
            ]);
        });
    }

    private function addSingleCurrentConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'create unique index "shop_doc_one_current_unique" on "shop_documents" ("shop_owner_id", "logical_slot") where "is_current" = 1',
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'create unique index "shop_doc_one_current_unique" on "shop_documents" ("shop_owner_id", "logical_slot") where "is_current" is true',
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `shop_documents` add `current_identity` varchar(191) generated always as (case when `is_current` = 1 then concat(`shop_owner_id`, ':', `logical_slot`) else null end) stored",
            );
            DB::statement(
                'create unique index `shop_doc_one_current_unique` on `shop_documents` (`current_identity`)',
            );

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement(
                'create unique index [shop_doc_one_current_unique] on [shop_documents] ([shop_owner_id], [logical_slot]) where [is_current] = 1',
            );
        }
    }

    private function dropSingleCurrentConstraint(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement('drop index [shop_doc_one_current_unique] on [shop_documents]');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('drop index `shop_doc_one_current_unique` on `shop_documents`');
            Schema::table('shop_documents', function (Blueprint $table): void {
                $table->dropColumn('current_identity');
            });
        } else {
            DB::statement('drop index if exists "shop_doc_one_current_unique"');
        }
    }
};
