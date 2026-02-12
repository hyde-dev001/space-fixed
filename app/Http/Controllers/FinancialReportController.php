<?php

namespace App\Http\Controllers;

use App\Models\Finance\Account;
use App\Models\Finance\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    /**
     * Get Balance Sheet data
     * Assets = Liabilities + Equity
     */
    public function balanceSheet(Request $request)
    {
        $date = $request->query('as_of_date', now()->toDateString());
        
        // Get authenticated user via Sanctum
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;

        // Get accounts grouped by type with balances as of the date
        $assets = $this->getAccountsByType(['Asset'], $date, $shopOwnerId);
        $liabilities = $this->getAccountsByType(['Liability'], $date, $shopOwnerId);
        $equity = $this->getAccountsByType(['Equity'], $date, $shopOwnerId);

        $totalAssets = $assets->sum('balance');
        $totalLiabilities = $liabilities->sum('balance');
        $totalEquity = $equity->sum('balance');

        return response()->json([
            'as_of_date' => $date,
            'assets' => [
                'accounts' => $assets->toArray(),
                'total' => $totalAssets,
            ],
            'liabilities' => [
                'accounts' => $liabilities->toArray(),
                'total' => $totalLiabilities,
            ],
            'equity' => [
                'accounts' => $equity->toArray(),
                'total' => $totalEquity,
            ],
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities_equity' => $totalLiabilities + $totalEquity,
                'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
            ],
        ]);
    }

    /**
     * Get Profit & Loss Statement
     * Revenues - Expenses = Net Income
     */
    public function profitLoss(Request $request)
    {
        $fromDate = $request->query('from_date', now()->startOfYear()->toDateString());
        $toDate = $request->query('to_date', now()->toDateString());
        
        // Get authenticated user via Sanctum
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;

        $revenues = $this->getAccountsByTypePeriod(['Revenue'], $fromDate, $toDate, $shopOwnerId);
        $expenses = $this->getAccountsByTypePeriod(['Expense'], $fromDate, $toDate, $shopOwnerId);

        $totalRevenues = $revenues->sum('balance');
        $totalExpenses = $expenses->sum('balance');
        $netIncome = $totalRevenues - $totalExpenses;

        return response()->json([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'revenues' => [
                'accounts' => $revenues->toArray(),
                'total' => $totalRevenues,
            ],
            'expenses' => [
                'accounts' => $expenses->toArray(),
                'total' => $totalExpenses,
            ],
            'summary' => [
                'total_revenues' => $totalRevenues,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome,
            ],
        ]);
    }

    /**
     * Get Trial Balance
     * Verify debits = credits
     */
    public function trialBalance(Request $request)
    {
        $date = $request->query('as_of_date', now()->toDateString());
        
        // Get authenticated user via Sanctum
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;

        $accounts = Account::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->get()
            ->map(function ($account) use ($date) {
                $debit = $this->getAccountDebitBalance($account->id, $date);
                $credit = $this->getAccountCreditBalance($account->id, $date);
                
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            })
            ->filter(fn($acc) => $acc['debit'] > 0 || $acc['credit'] > 0);

        $totalDebits = $accounts->sum('debit');
        $totalCredits = $accounts->sum('credit');
        $difference = abs($totalDebits - $totalCredits);

        return response()->json([
            'as_of_date' => $date,
            'accounts' => $accounts->values()->toArray(),
            'summary' => [
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'balanced' => $difference < 0.01,
                'difference' => $difference,
            ],
        ]);
    }

    /**
     * Get Accounts Receivable Aging
     */
    public function arAging(Request $request)
    {
        $date = $request->query('as_of_date', now()->toDateString());
        
        // Get authenticated user via Sanctum
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;

        // Get AR account (Accounts Receivable asset)
        $arAccount = Account::where('shop_owner_id', $shopOwnerId)
            ->where('type', 'Asset')
            ->where('code', 'like', '%1200%') // AR account code pattern
            ->first();

        if (!$arAccount) {
            return response()->json([
                'as_of_date' => $date,
                'buckets' => $this->getEmptyAgingBuckets(),
                'summary' => ['total_ar' => 0],
            ]);
        }

        // Get journal lines for AR
        $lines = JournalLine::where('account_id', $arAccount->id)
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('date', '<=', $date);
            })
            ->get();

        $buckets = $this->agingBuckets($lines, $date, 'ar');
        $totalAR = $buckets['current']['total'] + $buckets['thirty_days']['total'] + 
                   $buckets['sixty_days']['total'] + $buckets['ninety_days']['total'] + 
                   $buckets['over_120']['total'];

        return response()->json([
            'as_of_date' => $date,
            'buckets' => $buckets,
            'summary' => ['total_ar' => $totalAR],
        ]);
    }

    /**
     * Get Accounts Payable Aging
     */
    public function apAging(Request $request)
    {
        $date = $request->query('as_of_date', now()->toDateString());
        
        // Get authenticated user via Sanctum
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $shopOwnerId = $user->role === 'shop_owner' ? $user->id : $user->shop_owner_id;

        // Get AP account (Accounts Payable liability)
        $apAccount = Account::where('shop_owner_id', $shopOwnerId)
            ->where('type', 'Liability')
            ->where('code', 'like', '%2100%') // AP account code pattern
            ->first();

        if (!$apAccount) {
            return response()->json([
                'as_of_date' => $date,
                'buckets' => $this->getEmptyAgingBuckets(),
                'summary' => ['total_ap' => 0],
            ]);
        }

        // Get journal lines for AP
        $lines = JournalLine::where('account_id', $apAccount->id)
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('date', '<=', $date);
            })
            ->get();

        $buckets = $this->agingBuckets($lines, $date, 'ap');
        $totalAP = $buckets['current']['total'] + $buckets['thirty_days']['total'] + 
                   $buckets['sixty_days']['total'] + $buckets['ninety_days']['total'] + 
                   $buckets['over_120']['total'];

        return response()->json([
            'as_of_date' => $date,
            'buckets' => $buckets,
            'summary' => ['total_ap' => $totalAP],
        ]);
    }

    /**
     * Helper: Get accounts by type as of a specific date
     */
    private function getAccountsByType(array $types, string $date, $shopOwnerId)
    {
        return Account::where('shop_owner_id', $shopOwnerId)
            ->whereIn('type', $types)
            ->where('is_active', true)
            ->get()
            ->map(function ($account) use ($date) {
                $balance = $this->getAccountBalance($account->id, $date);
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'balance' => $balance,
                ];
            })
            ->filter(fn($acc) => $acc['balance'] != 0);
    }

    /**
     * Helper: Get accounts by type for a period (for P&L)
     */
    private function getAccountsByTypePeriod(array $types, string $fromDate, string $toDate, $shopOwnerId)
    {
        return Account::where('shop_owner_id', $shopOwnerId)
            ->whereIn('type', $types)
            ->where('is_active', true)
            ->get()
            ->map(function ($account) use ($fromDate, $toDate) {
                $balance = $this->getAccountBalancePeriod($account->id, $fromDate, $toDate);
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'balance' => $balance,
                ];
            })
            ->filter(fn($acc) => $acc['balance'] != 0);
    }

    /**
     * Helper: Get account balance as of date
     */
    private function getAccountBalance(int $accountId, string $date): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('date', '<=', $date)
                  ->where('status', 'posted');
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN type = "debit" THEN amount ELSE -amount END), 0) as balance')
            ->value('balance') ?? 0;
    }

    /**
     * Helper: Get account balance for a period
     */
    private function getAccountBalancePeriod(int $accountId, string $fromDate, string $toDate): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('date', [$fromDate, $toDate])
                  ->where('status', 'posted');
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN type = "debit" THEN amount ELSE -amount END), 0) as balance')
            ->value('balance') ?? 0;
    }

    /**
     * Helper: Get debit balance for trial balance
     */
    private function getAccountDebitBalance(int $accountId, string $date): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->where('type', 'debit')
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('date', '<=', $date)
                  ->where('status', 'posted');
            })
            ->sum('amount') ?? 0;
    }

    /**
     * Helper: Get credit balance for trial balance
     */
    private function getAccountCreditBalance(int $accountId, string $date): float
    {
        return (float) JournalLine::where('account_id', $accountId)
            ->where('type', 'credit')
            ->whereHas('journalEntry', function ($q) use ($date) {
                $q->where('date', '<=', $date)
                  ->where('status', 'posted');
            })
            ->sum('amount') ?? 0;
    }

    /**
     * Helper: Create aging buckets
     */
    private function agingBuckets($lines, string $asOfDate, string $type)
    {
        $currentDate = \Carbon\Carbon::parse($asOfDate);
        
        $buckets = [
            'current' => ['label' => 'Current (0-30)', 'items' => [], 'total' => 0],
            'thirty_days' => ['label' => '31-60 Days', 'items' => [], 'total' => 0],
            'sixty_days' => ['label' => '61-90 Days', 'items' => [], 'total' => 0],
            'ninety_days' => ['label' => '91-120 Days', 'items' => [], 'total' => 0],
            'over_120' => ['label' => '120+ Days', 'items' => [], 'total' => 0],
        ];

        foreach ($lines as $line) {
            $entryDate = $line->journalEntry->date;
            $daysOld = $currentDate->diffInDays($entryDate);
            $amount = $line->amount;

            $item = [
                'date' => $entryDate->toDateString(),
                'reference' => $line->journalEntry->reference,
                'amount' => $amount,
            ];

            if ($daysOld <= 30) {
                $buckets['current']['items'][] = $item;
                $buckets['current']['total'] += $amount;
            } elseif ($daysOld <= 60) {
                $buckets['thirty_days']['items'][] = $item;
                $buckets['thirty_days']['total'] += $amount;
            } elseif ($daysOld <= 90) {
                $buckets['sixty_days']['items'][] = $item;
                $buckets['sixty_days']['total'] += $amount;
            } elseif ($daysOld <= 120) {
                $buckets['ninety_days']['items'][] = $item;
                $buckets['ninety_days']['total'] += $amount;
            } else {
                $buckets['over_120']['items'][] = $item;
                $buckets['over_120']['total'] += $amount;
            }
        }

        return $buckets;
    }

    /**
     * Helper: Get empty aging buckets
     */
    private function getEmptyAgingBuckets()
    {
        return [
            'current' => ['label' => 'Current (0-30)', 'items' => [], 'total' => 0],
            'thirty_days' => ['label' => '31-60 Days', 'items' => [], 'total' => 0],
            'sixty_days' => ['label' => '61-90 Days', 'items' => [], 'total' => 0],
            'ninety_days' => ['label' => '91-120 Days', 'items' => [], 'total' => 0],
            'over_120' => ['label' => '120+ Days', 'items' => [], 'total' => 0],
        ];
    }
}
