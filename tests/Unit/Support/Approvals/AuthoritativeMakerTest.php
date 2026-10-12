<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Approvals;

use App\Support\Approvals\AuthoritativeMaker;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthoritativeMakerTest extends TestCase
{
    public function test_requires_exactly_one_authoritative_maker(): void
    {
        try {
            AuthoritativeMaker::from(null, null);
            $this->fail('A missing maker must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        try {
            AuthoritativeMaker::from(10, 20);
            $this->fail('Ambiguous makers must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_exposes_a_staff_maker_without_an_owner_maker(): void
    {
        $maker = AuthoritativeMaker::from(10, null);

        $this->assertTrue($maker->isStaff());
        $this->assertFalse($maker->isOwner());
        $this->assertSame(10, $maker->staffId());
        $this->assertNull($maker->shopOwnerId());
    }

    public function test_exposes_an_owner_maker_without_a_staff_maker(): void
    {
        $maker = AuthoritativeMaker::from(null, 20);

        $this->assertFalse($maker->isStaff());
        $this->assertTrue($maker->isOwner());
        $this->assertNull($maker->staffId());
        $this->assertSame(20, $maker->shopOwnerId());
    }
}
