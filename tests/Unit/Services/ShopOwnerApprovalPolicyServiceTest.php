<?php

namespace Tests\Unit\Services;

use App\Models\ProcurementSettings;
use App\Models\ShopOwner;
use App\Services\ShopOwnerApprovalPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

class ShopOwnerApprovalPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShopOwner $shopOwner;
    private ShopOwnerApprovalPolicyService $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopOwner = ShopOwner::factory()->approved()->create();
        $this->policy = app(ShopOwnerApprovalPolicyService::class);
    }

    public function test_missing_or_malformed_settings_require_owner_approval_for_all_families(): void
    {
        $this->assertAllFamilyPolicies(true);

        ProcurementSettings::create([
            'shop_owner_id' => $this->shopOwner->id,
            'settings_json' => [
                'approval_pages' => [
                    'refund_approval' => 'malformed',
                    'price_approval' => ['enabled' => 'malformed'],
                    'payslip_approval' => null,
                    'salary_adjustment_approval' => ['limit' => 10],
                    'purchase_request_approval' => [],
                    'expense_approval' => ['enabled' => null],
                    'repair_reject_approval' => ['enabled' => []],
                ],
            ],
        ]);

        $this->assertAllFamilyPolicies(true);
    }

    public function test_enabled_boolean_is_independent_of_legacy_limit_values(): void
    {
        $this->storeApprovalPages(true, 999999.99);
        $this->assertAllFamilyPolicies(true);

        $this->storeApprovalPages(false, 0);
        $this->assertAllFamilyPolicies(false);
    }

    public function test_unknown_internal_key_is_rejected_by_the_whitelisted_reader(): void
    {
        $this->assertTrue(method_exists($this->policy, 'readApprovalToggle'));

        if (! method_exists($this->policy, 'readApprovalToggle')) {
            return;
        }

        $reader = new ReflectionMethod($this->policy, 'readApprovalToggle');
        $reader->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $reader->invoke($this->policy, $this->shopOwner->id, 'not_a_supported_family');
    }

    private function assertAllFamilyPolicies(bool $expected): void
    {
        $readers = [
            'refund' => fn (): bool => $this->policy->requiresOwnerApprovalForRefund($this->shopOwner->id, 100.00),
            'price' => fn (): bool => $this->policy->requiresOwnerApprovalForPriceChange($this->shopOwner->id, 100.00, 125.00),
            'payslip' => fn (): bool => $this->policy->requiresOwnerApprovalForPayslip($this->shopOwner->id),
            'salary adjustment' => fn (): bool => $this->policy->requiresOwnerApprovalForSalaryAdjustment($this->shopOwner->id),
            'purchase request' => fn (): bool => $this->policy->requiresOwnerApprovalForPurchaseRequest($this->shopOwner->id, 100.00),
            'expense' => fn (): bool => $this->policy->requiresOwnerApprovalForExpense($this->shopOwner->id, 100.00),
            'repair reject' => fn (): bool => $this->policy->requiresOwnerApprovalForRepairReject($this->shopOwner->id, 100.00),
        ];

        foreach ($readers as $family => $reader) {
            $this->assertTrue(
                method_exists($this->policy, match ($family) {
                    'refund' => 'requiresOwnerApprovalForRefund',
                    'price' => 'requiresOwnerApprovalForPriceChange',
                    'payslip' => 'requiresOwnerApprovalForPayslip',
                    'salary adjustment' => 'requiresOwnerApprovalForSalaryAdjustment',
                    'purchase request' => 'requiresOwnerApprovalForPurchaseRequest',
                    'expense' => 'requiresOwnerApprovalForExpense',
                    'repair reject' => 'requiresOwnerApprovalForRepairReject',
                }),
                "Missing policy reader for {$family}.",
            );

            if (method_exists($this->policy, match ($family) {
                'refund' => 'requiresOwnerApprovalForRefund',
                'price' => 'requiresOwnerApprovalForPriceChange',
                'payslip' => 'requiresOwnerApprovalForPayslip',
                'salary adjustment' => 'requiresOwnerApprovalForSalaryAdjustment',
                'purchase request' => 'requiresOwnerApprovalForPurchaseRequest',
                'expense' => 'requiresOwnerApprovalForExpense',
                'repair reject' => 'requiresOwnerApprovalForRepairReject',
            })) {
                $this->assertSame($expected, $reader(), "Unexpected {$family} policy result.");
            }
        }
    }

    private function storeApprovalPages(bool $enabled, float $limit): void
    {
        $pages = [];
        foreach ([
            'refund_approval',
            'price_approval',
            'payslip_approval',
            'salary_adjustment_approval',
            'purchase_request_approval',
            'expense_approval',
            'repair_reject_approval',
        ] as $key) {
            $pages[$key] = ['enabled' => $enabled, 'limit' => $limit];
        }

        ProcurementSettings::updateOrCreate(
            ['shop_owner_id' => $this->shopOwner->id],
            ['settings_json' => ['approval_pages' => $pages]],
        );
    }
}
