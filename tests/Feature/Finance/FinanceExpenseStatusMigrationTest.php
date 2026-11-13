<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Tests\TestCase;

final class FinanceExpenseStatusMigrationTest extends TestCase
{
    public function test_finance_expenses_status_migration_restores_posted_for_procurement_release(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_09_14_000001_restore_posted_status_on_finance_expenses.php',
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString(
            "ENUM('draft', 'submitted', 'approved', 'posted', 'rejected')",
            $migration,
        );
    }
}
