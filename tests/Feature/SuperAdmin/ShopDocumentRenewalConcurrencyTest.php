<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use Tests\TestCase;

final class ShopDocumentRenewalConcurrencyTest extends TestCase
{
    public function test_database_engine_concurrency_evidence_is_deferred_until_a_shared_lock_harness_is_available(): void
    {
        $this->markTestSkipped('SQLite test runs cannot prove concurrent row-lock behavior; the transactional single-writer invariants are covered by ShopDocumentRenewalReviewTest.');
    }
}
