<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ConfirmCodRemittanceRequest;
use App\Models\CodRemittance;
use App\Services\CodRemittanceService;
use App\Support\Finance\FinanceDomainException;
use App\Support\Finance\FinanceErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CodRemittanceController extends Controller
{
    public function __construct(
        private readonly CodRemittanceService $remittances,
    ) {}

    public function index(): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'FORBIDDEN'], 401);
        }

        try {
            return response()->json($this->remittances->financeIndex($actor));
        } catch (FinanceDomainException $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.index', $exception->httpStatus);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.index');
        }
    }

    public function confirm(ConfirmCodRemittanceRequest $request, CodRemittance $remittance): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        if (! $actor) {
            return response()->json(['message' => 'Unauthorized', 'code' => 'FORBIDDEN'], 401);
        }

        try {
            $result = $this->remittances->confirm($remittance, $actor, $request->validated());

            return response()->json([
                'message' => $result['remittance']['status'] === CodRemittance::STATUS_DISPUTED
                    ? 'COD remittance disputed for variance review.'
                    : ($result['replayed'] ? 'COD remittance confirmation already recorded.' : 'COD remittance settled successfully.'),
                ...$result,
            ]);
        } catch (FinanceDomainException $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.confirm', $exception->httpStatus, [
                'record_id' => $remittance->id,
            ]);
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'cod.remittance.confirm', 500, [
                'record_id' => $remittance->id,
            ]);
        }
    }
}
