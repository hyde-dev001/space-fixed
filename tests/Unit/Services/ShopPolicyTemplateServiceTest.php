<?php

namespace Tests\Unit\Services;

use App\Services\ShopPolicyTemplateService;
use PHPUnit\Framework\TestCase;

class ShopPolicyTemplateServiceTest extends TestCase
{
    public function test_both_business_type_returns_all_sections(): void
    {
        $service = new ShopPolicyTemplateService();
        $result = $service->buildSections('both', 'individual');

        $this->assertArrayHasKey('refund_payment_terms', $result);
        $this->assertArrayHasKey('repair_service_terms', $result);
        $this->assertArrayHasKey('retail_terms', $result);
        $this->assertArrayNotHasKey('account_type_clause', $result);
    }

    public function test_repair_business_type_excludes_retail_terms(): void
    {
        $service = new ShopPolicyTemplateService();
        $result = $service->buildSections('repair', 'business');

        $this->assertArrayHasKey('refund_payment_terms', $result);
        $this->assertArrayHasKey('repair_service_terms', $result);
        $this->assertArrayNotHasKey('retail_terms', $result);
    }
}
