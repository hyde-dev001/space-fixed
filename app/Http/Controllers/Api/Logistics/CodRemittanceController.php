<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\SubmitCodRemittanceRequest;
use App\Services\CodRemittanceService;
use App\Support\Finance\FinanceDomainException;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CodRemittanceController extends Controller
{
    public function __construct(
        private readonly CodRemittanceService $remittances,
    ) {}

    public function store(SubmitCodRemittanceRequest $request): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor || ! $actor->can('operate-logistics-deliveries')) {
            throw new AuthorizationException('Only active logistics riders can submit COD remittances.');
        }

        try {
            $result = $this->remittances->submit($actor, $request->validated());

            return response()->json([
                'message' => $result['replayed']
                    ? 'COD remittance request already recorded.'
                    : 'COD remittance submitted for Finance confirmation.',
                ...$result,
            ], $result['replayed'] ? 200 : 201);
        } catch (FinanceDomainException $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.submit', $exception->httpStatus);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.submit');
        }
    }
}
