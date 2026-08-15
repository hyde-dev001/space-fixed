<?php

declare(strict_types=1);

namespace Tests\Unit\Support\OwnerShell;

use App\Enums\OwnerShellPresentation;
use App\Enums\OwnerShellSelectionReason;
use App\Support\OwnerShell\OwnerShellGroup;
use App\Support\OwnerShell\OwnerShellItem;
use App\Support\OwnerShell\OwnerShellMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OwnerShellMetadataTest extends TestCase
{
    public function test_existing_presentation_serializes_without_canonical_groups_or_fallback(): void
    {
        $metadata = new OwnerShellMetadata(
            OwnerShellPresentation::Existing,
            OwnerShellSelectionReason::GlobalDisabled,
            null,
            [],
            [
                'show_erp_fallback' => false,
                'erp_workspace_url' => null,
                'fallback_url' => null,
            ],
        );

        $this->assertSame([
            'presentation' => 'existing',
            'selection_reason' => 'global_disabled',
            'context' => null,
            'groups' => [],
            'compatibility' => [
                'show_erp_fallback' => false,
                'erp_workspace_url' => null,
                'fallback_url' => null,
            ],
        ], $metadata->toArray());
    }

    public function test_canonical_presentation_requires_individual_or_company_context(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerShellMetadata(
            OwnerShellPresentation::Canonical,
            OwnerShellSelectionReason::ShopAllowlisted,
            null,
            [],
            $this->compatibility(),
        );
    }

    public function test_existing_presentation_cannot_carry_canonical_groups_or_context(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerShellMetadata(
            OwnerShellPresentation::Existing,
            OwnerShellSelectionReason::GlobalDisabled,
            'company',
            [$this->group()],
            $this->compatibility(),
        );
    }

    public function test_canonical_metadata_serializes_bounded_items_and_compatibility(): void
    {
        $metadata = new OwnerShellMetadata(
            OwnerShellPresentation::Canonical,
            OwnerShellSelectionReason::ShopAllowlisted,
            'individual',
            [$this->group()],
            $this->compatibility(),
        );

        $this->assertSame([
            'presentation' => 'canonical',
            'selection_reason' => 'shop_allowlisted',
            'context' => 'individual',
            'groups' => [[
                'key' => 'operate',
                'label' => 'Operate',
                'order' => 10,
                'default_expanded' => true,
                'items' => [[
                    'key' => 'retail',
                    'label' => 'Retail',
                    'canonical_url' => '/shop-owner/operate/retail',
                    'available' => true,
                    'unavailable_reason' => null,
                    'management_url' => null,
                    'active_matching' => [
                        '/shop-owner/operate/retail',
                        '/shop-owner/erp/retail*',
                    ],
                ]],
            ]],
            'compatibility' => $this->compatibility(),
        ], $metadata->toArray());
    }

    public function test_group_with_no_items_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerShellGroup('operate', 'Operate', 10, true, []);
    }

    public function test_unavailable_items_require_a_stable_reason_and_management_destination(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerShellItem(
            'finance',
            'Finance',
            '/shop-owner/oversee/finance',
            false,
            null,
            null,
            ['/shop-owner/oversee/finance'],
        );
    }

    public function test_unavailable_item_with_bounded_reason_and_management_destination_is_valid(): void
    {
        $item = new OwnerShellItem(
            'finance',
            'Finance',
            '/shop-owner/oversee/finance',
            false,
            'module_disabled',
            '/shop-owner/settings/modules-team',
            ['/shop-owner/oversee/finance', '/shop-owner/erp/finance*'],
        );

        $this->assertFalse($item->toArray()['available']);
    }

    public function test_keys_reasons_urls_and_active_matching_are_bounded(): void
    {
        $cases = [
            static fn (): OwnerShellItem => new OwnerShellItem(
                'Finance Summary',
                'Finance',
                '/shop-owner/oversee/finance',
                true,
                null,
                null,
                ['/shop-owner/oversee/finance'],
            ),
            static fn (): OwnerShellItem => new OwnerShellItem(
                'finance',
                'Finance',
                'https://example.test/finance',
                true,
                null,
                null,
                ['/shop-owner/oversee/finance'],
            ),
            static fn (): OwnerShellItem => new OwnerShellItem(
                'finance',
                'Finance',
                '/shop-owner/oversee/finance',
                false,
                'Module disabled for this shop',
                '/shop-owner/settings/modules-team',
                ['/shop-owner/oversee/finance'],
            ),
            static fn (): OwnerShellItem => new OwnerShellItem(
                'finance',
                'Finance',
                '/shop-owner/oversee/finance',
                true,
                null,
                null,
                ['/erp/finance'],
            ),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Malformed shell metadata was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_malformed_canonical_metadata_cannot_be_serialized(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OwnerShellMetadata(
            OwnerShellPresentation::Canonical,
            OwnerShellSelectionReason::ShopAllowlisted,
            'microbusiness',
            [$this->group()],
            $this->compatibility(),
        );
    }

    /**
     * @return array{show_erp_fallback: bool, erp_workspace_url: string, fallback_url: string}
     */
    private function compatibility(): array
    {
        return [
            'show_erp_fallback' => true,
            'erp_workspace_url' => '/shop-owner/erp/workspace',
            'fallback_url' => '/shop-owner/erp/fallback',
        ];
    }

    private function group(): OwnerShellGroup
    {
        return new OwnerShellGroup(
            'operate',
            'Operate',
            10,
            true,
            [new OwnerShellItem(
                'retail',
                'Retail',
                '/shop-owner/operate/retail',
                true,
                null,
                null,
                ['/shop-owner/operate/retail', '/shop-owner/erp/retail*'],
            )],
        );
    }
}
