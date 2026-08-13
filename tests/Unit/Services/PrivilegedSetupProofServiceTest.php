<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PrivilegedSetupProofService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

final class PrivilegedSetupProofServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_issues_a_bounded_proof_that_recovers_the_authorized_ids(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 13, 12, 0, 0, 'UTC'));

        $proof = app(PrivilegedSetupProofService::class)->issue(
            tokenId: 10,
            subjectId: 20,
            tokenExpiresAt: now()->addDay(),
        );

        self::assertSame([
            'token_id' => 10,
            'subject_id' => 20,
        ], app(PrivilegedSetupProofService::class)->authorization($proof));

        Carbon::setTestNow(now()->addMinutes(16));

        $this->expectException(InvalidArgumentException::class);
        app(PrivilegedSetupProofService::class)->authorization($proof);
    }

    public function test_it_rejects_a_modified_proof(): void
    {
        $proof = app(PrivilegedSetupProofService::class)->issue(
            tokenId: 10,
            subjectId: 20,
            tokenExpiresAt: now()->addDay(),
        );
        $modifiedProof = substr_replace($proof, $proof[10] === 'A' ? 'B' : 'A', 10, 1);

        $this->expectException(InvalidArgumentException::class);
        app(PrivilegedSetupProofService::class)->authorization($modifiedProof);
    }

    public function test_it_rejects_an_authenticated_payload_with_the_wrong_purpose(): void
    {
        $proof = app(PrivilegedSetupProofService::class)->issue(
            tokenId: 10,
            subjectId: 20,
            tokenExpiresAt: now()->addDay(),
        );
        $payload = json_decode(Crypt::decryptString($proof), true, flags: JSON_THROW_ON_ERROR);
        $payload['purpose'] = 'reset';
        $wrongPurposeProof = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        app(PrivilegedSetupProofService::class)->authorization($wrongPurposeProof);
    }
}
