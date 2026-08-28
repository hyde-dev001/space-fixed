<?php

namespace Tests\Feature;

use Tests\TestCase;

final class TestingEnvironmentTest extends TestCase
{
    public function test_test_environment_provides_an_application_encryption_key(): void
    {
        $this->assertNotSame('', (string) config('app.key'));
    }
}
