<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class PaymongoWebhookSignatureTest extends TestCase
{
    public function test_paymongo_test_mode_signature_uses_timestamp_and_raw_body(): void
    {
        $secret = 'whsk_test_signature';
        config()->set('services.paymongo.webhook_secret', $secret);
        $timestamp = (string) time();
        $body = $this->body();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        ], $body)
            ->assertOk()
            ->assertJson(['message' => 'Event received']);
    }

    public function test_invalid_paymongo_signature_is_rejected_before_event_processing(): void
    {
        config()->set('services.paymongo.webhook_secret', 'whsk_test_signature');
        $timestamp = (string) time();

        $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te=not-a-signature,li=",
        ], $this->body())
            ->assertUnauthorized()
            ->assertJson(['message' => 'Invalid webhook signature']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'data' => [
                'id' => 'evt_signature_test',
                'type' => 'event',
                'attributes' => [
                    'type' => 'unhandled.event',
                    'livemode' => false,
                    'data' => [
                        'id' => 'resource_signature_test',
                        'type' => 'resource',
                        'attributes' => [],
                    ],
                ],
            ],
        ];
    }

    private function body(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR);
    }
}
