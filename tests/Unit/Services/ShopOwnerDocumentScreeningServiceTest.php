<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ShopOwnerDocumentScreeningService;
use Tests\TestCase;

final class ShopOwnerDocumentScreeningServiceTest extends TestCase
{
    private function service(): ShopOwnerDocumentScreeningService
    {
        self::assertTrue(
            class_exists(ShopOwnerDocumentScreeningService::class),
            'The shop owner screening contract service has not been implemented yet.',
        );

        return app(ShopOwnerDocumentScreeningService::class);
    }

    private function contractFixtures(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/ShopOwnerOcr/screening-contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_paired_contract_cases_match_expected_document_scores(): void
    {
        $service = $this->service();

        foreach ($this->contractFixtures()['cases'] as $case) {
            $result = $service->scoreEntry(
                $case['slot'],
                $case['selected_document_type'],
                $case['entry'],
            );

            self::assertSame($case['expected_score'], $result['score'], $case['slot']);
        }
    }

    public function test_only_absent_or_empty_optional_envelopes_are_omitted(): void
    {
        $service = $this->service();

        self::assertSame([], $service->validateRegistrationInput(null, []));
        self::assertSame([], $service->validateRegistrationInput('', []));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->validateRegistrationInput('   ', []);
    }

    public function test_paired_contract_cases_match_expected_aggregate_states(): void
    {
        $service = $this->service();
        $fixtures = $this->contractFixtures();

        foreach ($fixtures['summaries'] as $summary) {
            self::assertSame(
                $summary['expected'],
                $service->summarizeScores($summary['score_map']),
                $summary['id'],
            );
        }
    }

    public function test_sub_high_local_confidence_cannot_pass_even_with_high_document_confidence(): void
    {
        $service = $this->service();
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];
        $entry['recognition_confidence'] = 0.99;
        $entry['checks']['proprietor_identity']['confidence'] = 0.84999;

        self::assertSame(50, $service->scoreEntry('business_registration', 'dti_registration', $entry)['score']);
    }

    public function test_unclear_bir_taxpayer_role_keeps_business_identity_uncertain(): void
    {
        $entry = $this->contractFixtures()['cases']['birCorporate100']['entry'];
        $entry['relationship'] = 'unclear';

        self::assertSame(50, $this->service()->scoreEntry('bir_certificate', 'bir_certificate', $entry)['score']);
    }

    public function test_confidence_bands_use_the_agreed_boundaries(): void
    {
        $service = $this->service();

        self::assertSame('high', $service->confidenceBand(0.85));
        self::assertSame('medium', $service->confidenceBand(0.65));
        self::assertSame('low', $service->confidenceBand(0.64999));
    }

    public function test_unavailable_reading_cannot_contain_an_eligible_match(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $entry = $this->contractFixtures()['cases']['unavailable']['entry'];
        $entry['detected_family'] = 'national_id';
        $entry['checks']['document_type'] = [
            'result' => 'match',
            'confidence' => 0.96,
            'complete_value' => true,
            'field_associated' => true,
            'unambiguous' => true,
            'independent_type_signals' => true,
        ];

        $this->service()->scoreEntry('valid_id', 'valid_id', $entry);
    }

    public function test_envelope_accepts_the_utf8_byte_limit_and_rejects_one_byte_over(): void
    {
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];
        $json = json_encode(['business_registration' => $entry], JSON_THROW_ON_ERROR);
        $belowLimit = $json.str_repeat(' ', 32767 - strlen($json));
        $atLimit = $json.str_repeat(' ', 32768 - strlen($json));

        self::assertSame(32767, strlen($belowLimit));
        self::assertSame(32768, strlen($atLimit));
        self::assertArrayHasKey(
            'business_registration',
            $this->service()->validateEnvelope($belowLimit, ['business_registration' => 'dti_registration']),
        );
        self::assertArrayHasKey(
            'business_registration',
            $this->service()->validateEnvelope($atLimit, ['business_registration' => 'dti_registration']),
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->validateEnvelope($atLimit.' ', ['business_registration' => 'dti_registration']);
    }

    public function test_oversized_multibyte_envelope_is_counted_as_utf8_bytes(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service()->validateEnvelope(str_repeat('é', 16385), []);
    }

    public function test_envelope_rejects_unknown_fields_and_unrecognized_reason_codes(): void
    {
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];
        $entry['applicant_score'] = 100;
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->validateEnvelope(
            json_encode(['business_registration' => $entry], JSON_THROW_ON_ERROR),
            ['business_registration' => 'dti_registration'],
        );
    }

    public function test_envelope_rejects_unknown_reason_codes(): void
    {
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];
        $entry['reason_codes'] = ['a_free_text_reason'];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service()->validateEnvelope(
            json_encode(['business_registration' => $entry], JSON_THROW_ON_ERROR),
            ['business_registration' => 'dti_registration'],
        );
    }

    public function test_envelope_rejects_unknown_slots_check_keys_and_sensitive_fields(): void
    {
        $service = $this->service();
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];

        try {
            $service->validateEnvelope(json_encode(['supporting_document' => $entry], JSON_THROW_ON_ERROR), []);
            self::fail('Unknown document slots must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $entry['checks']['owner_identity'] = $entry['checks']['business_identity'];
        try {
            $service->validateEnvelope(
                json_encode(['business_registration' => $entry], JSON_THROW_ON_ERROR),
                ['business_registration' => 'dti_registration'],
            );
            self::fail('Check keys outside the fixed slot/type contract must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        foreach (['score', 'approved_at', 'reviewer_id', 'extracted_text'] as $key) {
            $prohibited = $this->contractFixtures()['cases']['dti100']['entry'];
            $prohibited[$key] = 'synthetic';
            try {
                $service->validateEnvelope(
                    json_encode(['business_registration' => $prohibited], JSON_THROW_ON_ERROR),
                    ['business_registration' => 'dti_registration'],
                );
                self::fail("The prohibited {$key} field must be rejected.");
            } catch (\Illuminate\Validation\ValidationException) {
            }
        }
    }

    public function test_envelope_rejects_duplicate_or_excess_reason_codes(): void
    {
        foreach ([
            ['uncertain_field', 'uncertain_field'],
            ['wrong_document_type', 'business_identity_conflict', 'owner_identity_conflict', 'proprietor_identity_conflict', 'business_address_conflict', 'date_conflict', 'uncertain_field', 'low_recognition_confidence', 'unsupported_layout'],
        ] as $reasons) {
            $entry = $this->contractFixtures()['cases']['dti100']['entry'];
            $entry['reason_codes'] = $reasons;

            try {
                $this->service()->validateEnvelope(
                    json_encode(['business_registration' => $entry], JSON_THROW_ON_ERROR),
                    ['business_registration' => 'dti_registration'],
                );
                self::fail('Duplicate or excess reason codes must be rejected.');
            } catch (\Illuminate\Validation\ValidationException) {
            }
        }
    }

    public function test_non_finite_local_confidence_is_rejected(): void
    {
        $entry = $this->contractFixtures()['cases']['dti100']['entry'];
        $entry['checks']['business_identity']['confidence'] = INF;
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->service()->scoreEntry('business_registration', 'dti_registration', $entry);
    }

    public function test_invalid_json_and_out_of_range_confidence_are_rejected(): void
    {
        try {
            $this->service()->validateEnvelope('{not json', []);
            self::fail('Malformed JSON must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        foreach ([-0.01, 1.01] as $confidence) {
            $entry = $this->contractFixtures()['cases']['dti100']['entry'];
            $entry['checks']['business_identity']['confidence'] = $confidence;
            try {
                $this->service()->scoreEntry('business_registration', 'dti_registration', $entry);
                self::fail('Out-of-range confidence must be rejected.');
            } catch (\Illuminate\Validation\ValidationException) {
            }
        }
    }
}
