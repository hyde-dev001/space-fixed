<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessScaling;

use App\Services\ErpRouteCatalog;
use Tests\TestCase;

final class ErpRouteCatalogTest extends TestCase
{
    public function test_capability_keys_are_stable_and_method_normalized(): void
    {
        $this->assertSame('GET:erp.hr', ErpRouteCatalog::capabilityKey('get', 'erp.hr'));
        $this->assertSame('POST:erp.hr', ErpRouteCatalog::capabilityKey('POST', 'erp.hr'));
    }

    public function test_route_lookup_requires_a_cataloged_method(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        $this->assertSame('erp.hr', $catalog->forRoute('GET', 'erp.hr')['route_name']);
        $this->assertNull($catalog->forRoute('POST', 'erp.hr'));
        $this->assertNull($catalog->forRoute('GET', 'missing.route'));
    }

    public function test_employee_route_is_the_canonical_client_key_until_a_pair_exists(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        $this->assertSame('GET:erp.hr', $catalog->canonicalClientKey('GET', 'erp.hr'));
        $this->assertNull($catalog->ownerExposure('GET', 'erp.hr'));
        $this->assertIsString($catalog->employeeRule('erp.hr'));
    }
}
