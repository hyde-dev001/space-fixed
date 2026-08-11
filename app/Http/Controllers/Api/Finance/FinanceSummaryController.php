<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceSummaryService;
use App\Support\Finance\FinanceErrorResponse;
use App\Support\Finance\FinanceShopContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class FinanceSummaryController extends Controller
{
    public function __construct(
        private readonly FinanceShopContext $shopContext,
        private readonly FinanceSummaryService $summaryService,
    ) {
    }

    public function __invoke(Request $request)
    {
        $shopId = $this->shopContext->id($request);

        try {
            return response()->json($this->summaryService->forCurrentPeriod(
                $shopId,
                CarbonImmutable::now(config('app.timezone')),
            ));
        } catch (\Throwable $exception) {
            return FinanceErrorResponse::json($exception, 'finance.dashboard', 500, ['shop_id' => $shopId]);
        }
    }
}
