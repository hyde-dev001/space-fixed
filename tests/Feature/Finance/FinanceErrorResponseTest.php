<?php

namespace Tests\Feature\Finance;

use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FinanceErrorResponseTest extends TestCase
{
    public function test_finance_error_response_hides_exception_details_and_logs_context(): void
    {
        Log::spy();

        $exception = new \RuntimeException('SQLSTATE[42S02]: secret table path /srv/private');
        $response = FinanceErrorResponse::json($exception, 'finance.test', 500, [
            'shop_id' => 42,
            'record_id' => 7,
        ]);

        $this->assertSame(500, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('INTERNAL_ERROR', $payload['code']);
        $this->assertSame('The Finance operation could not be completed.', $payload['message']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($payload));
        $this->assertStringNotContainsString('/srv/private', json_encode($payload));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($exception): bool {
                return $message === 'Finance operation failed'
                    && $context['operation'] === 'finance.test'
                    && $context['shop_id'] === 42
                    && $context['record_id'] === 7
                    && $context['exception'] === $exception;
            });
    }
}
