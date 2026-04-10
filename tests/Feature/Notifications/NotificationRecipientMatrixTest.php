<?php

namespace Tests\Feature\Notifications;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationRecipientMatrixTest extends TestCase
{
    #[Test]
    public function governance_events_resolve_owner_for_both_individual_and_company(): void
    {
        $resolver = app(\App\Services\Notifications\RecipientResolver::class);

        $individual = $resolver->resolveShopOwnerRecipients('salary_change_submitted', 1001, 'individual');
        $company = $resolver->resolveShopOwnerRecipients('salary_change_submitted', 2001, 'company');

        $this->assertNotEmpty($individual['shop_owner_ids']);
        $this->assertNotEmpty($company['shop_owner_ids']);
    }
}
