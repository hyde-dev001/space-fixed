<?php

namespace App\Support\Finance;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FinanceErrorResponse
{
    public static function json(Throwable $exception, string $operation, int $status = 500, array $context = []): JsonResponse
    {
        Log::error('Finance operation failed', [
            'operation' => $operation,
            'actor_id' => Auth::guard('user')->id(),
            'shop_id' => $context['shop_id'] ?? request()->attributes->get('user_shop_id'),
            'record_id' => $context['record_id'] ?? null,
            'request_id' => request()->header('X-Request-ID') ?? request()->header('X-Correlation-ID'),
            'exception' => $exception,
        ]);

        if ($exception instanceof FinanceDomainException) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }

        return response()->json([
            'message' => 'The Finance operation could not be completed.',
            'code' => 'INTERNAL_ERROR',
        ], $status);
    }
}
