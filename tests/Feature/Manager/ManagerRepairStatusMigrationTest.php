<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use Tests\TestCase;

final class ManagerRepairStatusMigrationTest extends TestCase
{
    public function test_assignment_state_migration_preserves_the_existing_repair_status_vocabulary(): void
    {
        $migrationPaths = [
            'migrations/2026_08_27_000001_add_manager_assignment_states_to_repair_requests_table.php',
            'migrations/2026_08_28_000002_restore_shipped_status_on_repair_requests_table.php',
        ];

        foreach ($migrationPaths as $migrationPath) {
            $migration = file_get_contents(database_path($migrationPath));

            $this->assertIsString($migration);
            $minimumOccurrences = str_contains($migrationPath, '2026_08_27_000001') ? 2 : 1;

            foreach ([
                'new_request',
                'assigned_to_repairer',
                'repairer_accepted',
                'waiting_customer_confirmation',
                'owner_approval_pending',
                'owner_approved',
                'owner_rejected',
                'confirmed',
                'in_progress',
                'awaiting_parts',
                'completed',
                'ready_for_pickup',
                'shipped',
                'picked_up',
                'pending',
                'received',
                'cancelled',
                'rejected',
                'repairer_rejected',
                'manager_reviewing',
                'manager_approved',
                'manager_rejected',
            ] as $status) {
                $this->assertGreaterThanOrEqual(
                    $minimumOccurrences,
                    substr_count($migration, "'{$status}'"),
                    "The {$status} status must be preserved in {$migrationPath}.",
                );
            }
        }
    }
}
