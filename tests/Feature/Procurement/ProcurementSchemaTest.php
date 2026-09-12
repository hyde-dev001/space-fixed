<?php

namespace Tests\Feature\Procurement;

use Tests\TestCase;

class ProcurementSchemaTest extends TestCase
{
    public function test_mysql_migration_allows_the_finance_final_status(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_02_000001_harden_purchase_request_approval.php'));

        $this->assertMatchesRegularExpression(
            "/enum\\('status'.*pending_finance_final/s",
            $migration
        );
    }
}
