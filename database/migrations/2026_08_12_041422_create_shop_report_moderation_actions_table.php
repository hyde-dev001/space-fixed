<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL executes the table creation before applying the indexes. If a
        // deployment fails while adding an index, the next attempt must finish
        // that partial table instead of trying to create it again.
        if (Schema::hasTable('shop_report_moderation_actions')) {
            $warningStrikeIndexExists = collect(Schema::getIndexes('shop_report_moderation_actions'))
                ->contains(static function (array $index): bool {
                    return ($index['name'] ?? null) === 'shop_report_actions_owner_warning_unique'
                        || ($index['columns'] ?? []) === ['shop_owner_id', 'warning_strike_number'];
                });

            if (! $warningStrikeIndexExists) {
                Schema::table('shop_report_moderation_actions', function (Blueprint $table): void {
                    $table->unique(
                        ['shop_owner_id', 'warning_strike_number'],
                        'shop_report_actions_owner_warning_unique',
                    );
                });
            }

            return;
        }

        Schema::create('shop_report_moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('super_admins')->restrictOnDelete();
            $table->string('requested_action', 32);
            $table->string('applied_action', 32);
            $table->json('report_ids');
            $table->string('decision_key', 64)->nullable()->unique();
            $table->unsignedInteger('warning_strike_number')->nullable();
            $table->string('source', 32)->default('runtime');
            $table->unsignedBigInteger('legacy_audit_log_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_owner_id', 'created_at']);
            $table->unique(
                ['shop_owner_id', 'warning_strike_number'],
                'shop_report_actions_owner_warning_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_report_moderation_actions');
    }
};
