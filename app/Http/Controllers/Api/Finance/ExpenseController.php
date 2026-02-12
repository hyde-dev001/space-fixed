<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Finance\Expense;
use App\Models\Finance\Account;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\JournalLine;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $expenses = QueryBuilder::for(Expense::class)
            ->allowedFilters([
                'status',
                'category',
                'vendor',
                AllowedFilter::partial('search', 'reference'),
                AllowedFilter::scope('date_from'),
                AllowedFilter::scope('date_to'),
                AllowedFilter::scope('search_all'),
            ])
            ->allowedSorts(['date', 'amount', 'created_at', 'reference'])
            ->defaultSort('-date')
            ->paginate($request->get('per_page', 15));

        return response()->json($expenses);
    }

    public function show($id)
    {
        $expense = Expense::with('journalEntry.lines')->findOrFail($id);
        return response()->json($expense);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => 'nullable|string|unique:finance_expenses,reference',
            'date' => 'required|date',
            'category' => 'required|string|max:191',
            'vendor' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,submitted,approved,posted,rejected',
            'expense_account_id' => 'nullable|integer|exists:finance_accounts,id',
            'payment_account_id' => 'nullable|integer|exists:finance_accounts,id',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        try {
            DB::beginTransaction();

            $reference = $data['reference'] ?? ('EXP-' . now()->format('YmdHis') . '-' . random_int(100, 999));

            $expenseData = [
                'reference' => $reference,
                'date' => $data['date'],
                'category' => $data['category'],
                'vendor' => $data['vendor'] ?? null,
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'tax_amount' => $data['tax_amount'] ?? 0,
                'status' => $data['status'] ?? 'submitted',
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'payment_account_id' => $data['payment_account_id'] ?? null,
                'shop_id' => auth()->user()?->shop_owner_id ?? 1,
                'meta' => [
                    'created_by' => auth()->id(),
                ],
            ];

            // Handle receipt upload
            if ($request->hasFile('receipt')) {
                $file = $request->file('receipt');
                $fileName = time() . '_' . $reference . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('receipts', $fileName, 'public');
                
                $expenseData['receipt_path'] = $path;
                $expenseData['receipt_original_name'] = $file->getClientOriginalName();
                $expenseData['receipt_mime_type'] = $file->getMimeType();
                $expenseData['receipt_size'] = $file->getSize();
            }

            $expense = Expense::create($expenseData);

            $this->audit('create_expense', $expense->id, $expense->toArray());

            DB::commit();
            return response()->json($expense, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Expense creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to create expense', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['draft', 'submitted'])) {
            return response()->json(['message' => 'Only draft/submitted expenses can be edited'], 422);
        }

        $data = $request->validate([
            'date' => 'sometimes|date',
            'category' => 'sometimes|string|max:191',
            'vendor' => 'sometimes|nullable|string|max:191',
            'description' => 'sometimes|nullable|string',
            'amount' => 'sometimes|numeric|min:0.01',
            'tax_amount' => 'sometimes|numeric|min:0',
            'expense_account_id' => 'sometimes|nullable|integer|exists:finance_accounts,id',
            'payment_account_id' => 'sometimes|nullable|integer|exists:finance_accounts,id',
        ]);

        $expense->update($data);
        $this->audit('update_expense', $expense->id, $data);

        return response()->json($expense);
    }

    public function approve(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['submitted', 'draft'])) {
            return response()->json(['message' => 'Only submitted expenses can be approved'], 422);
        }

        // Check approval limit
        $user = Auth::user();
        $approvalLimit = $user->approval_limit;
        
        // If approval_limit is null, user has unlimited approval authority (e.g., Finance Manager)
        // If approval_limit is set, check if it's sufficient
        if ($approvalLimit !== null && $expense->amount > $approvalLimit) {
            return response()->json([
                'message' => 'Insufficient approval authority',
                'details' => [
                    'expense_amount' => $expense->amount,
                    'your_approval_limit' => $approvalLimit,
                    'required_approver' => 'This expense requires approval from a user with higher authority'
                ]
            ], 403);
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $request->input('approval_notes'),
        ]);

        $this->audit('approve_expense', $expense->id, ['status' => 'approved']);

        return response()->json($expense);
    }

    public function reject(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['submitted', 'draft'])) {
            return response()->json(['message' => 'Only submitted expenses can be rejected'], 422);
        }

        // Check approval limit (same authority required to reject as to approve)
        $user = Auth::user();
        $approvalLimit = $user->approval_limit;
        
        // If approval_limit is null, user has unlimited approval authority (e.g., Finance Manager)
        // If approval_limit is set, check if it's sufficient
        if ($approvalLimit !== null && $expense->amount > $approvalLimit) {
            return response()->json([
                'message' => 'Insufficient approval authority',
                'details' => [
                    'expense_amount' => $expense->amount,
                    'your_approval_limit' => $approvalLimit,
                    'required_approver' => 'This expense requires approval/rejection from a user with higher authority'
                ]
            ], 403);
        }

        $expense->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $request->input('approval_notes'),
        ]);

        $this->audit('reject_expense', $expense->id, ['status' => 'rejected']);

        return response()->json($expense);
    }

    public function post(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status === 'posted') {
            return response()->json(['message' => 'Expense already posted'], 422);
        }

        try {
            DB::beginTransaction();

            if (!$expense->journal_entry_id) {
                $entry = $this->createJournalEntry($expense);
                $expense->update(['journal_entry_id' => $entry->id]);
            }

            $entry = $expense->journalEntry;
            $entry->status = 'posted';
            $entry->posted_at = now();
            $entry->posted_by = auth()->id();
            $entry->save();

            $expense->update(['status' => 'posted']);

            foreach ($entry->lines as $line) {
                $account = Account::find($line->account_id);
                $oldBalance = $account->balance;
                if ($account->normal_balance === 'Debit') {
                    $newBalance = $oldBalance + $line->debit - $line->credit;
                } else {
                    $newBalance = $oldBalance + $line->credit - $line->debit;
                }
                $account->update(['balance' => $newBalance]);
            }

            $this->audit('post_expense', $expense->id, ['status' => 'posted']);

            DB::commit();
            return response()->json($expense->fresh());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Expense posting failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to post expense', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, ['draft', 'submitted', 'rejected'])) {
            return response()->json(['message' => 'Only unposted expenses can be deleted'], 422);
        }

        $expense->delete();
        $this->audit('delete_expense', $expense->id, ['status' => $expense->status]);

        return response()->json(['message' => 'Expense deleted']);
    }

    private function createJournalEntry(Expense $expense): JournalEntry
    {
        $total = (float)$expense->amount + (float)$expense->tax_amount;

        $expenseAccount = $expense->expenseAccount ?: Account::where('type', 'Expense')
            ->where('active', true)
            ->orderBy('code')
            ->first();

        if (!$expenseAccount) {
            $expenseAccount = Account::create([
                'code' => '5000',
                'name' => 'General Expense',
                'type' => 'Expense',
                'normal_balance' => 'Debit',
                'group' => 'Operating Expenses',
                'active' => true,
                'shop_id' => $expense->shop_id,
            ]);
        }

        $paymentAccount = $expense->paymentAccount ?: Account::where('type', 'Liability')
            ->where('active', true)
            ->where(function ($q) {
                $q->where('code', '2000')->orWhere('name', 'LIKE', '%Payable%');
            })
            ->orderBy('code')
            ->first();

        if (!$paymentAccount) {
            $paymentAccount = Account::create([
                'code' => '2000',
                'name' => 'Accounts Payable',
                'type' => 'Liability',
                'normal_balance' => 'Credit',
                'group' => 'Payables',
                'active' => true,
                'shop_id' => $expense->shop_id,
            ]);
        }

        $entry = JournalEntry::create([
            'reference' => 'EXP-' . $expense->reference,
            'date' => $expense->date,
            'description' => "Expense {$expense->reference}: {$expense->category}",
            'status' => 'draft',
            'meta' => [
                'source' => 'expense',
                'expense_id' => $expense->id,
            ],
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAccount->id,
            'account_code' => $expenseAccount->code,
            'account_name' => $expenseAccount->name,
            'debit' => $total,
            'credit' => 0,
            'memo' => "Expense {$expense->reference}",
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentAccount->id,
            'account_code' => $paymentAccount->code,
            'account_name' => $paymentAccount->name,
            'debit' => 0,
            'credit' => $total,
            'memo' => "Expense payable {$expense->reference}",
        ]);

        return $entry;
    }

    private function audit(string $action, int $targetId, array $metadata = []): void
    {
        $actorUserId = Auth::guard('user')->id() ?? Auth::id();
        $shopOwnerId = Auth::user()?->shop_owner_id ?? 1;
        AuditLog::create([
            'shop_owner_id' => $shopOwnerId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => 'expense',
            'target_id' => $targetId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Upload or replace receipt for an existing expense
     */
    public function uploadReceipt(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ]);

        try {
            DB::beginTransaction();

            // Delete old receipt if exists
            if ($expense->receipt_path) {
                Storage::disk('public')->delete($expense->receipt_path);
            }

            // Upload new receipt
            $file = $request->file('receipt');
            $fileName = time() . '_' . $expense->reference . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('receipts', $fileName, 'public');

            $expense->update([
                'receipt_path' => $path,
                'receipt_original_name' => $file->getClientOriginalName(),
                'receipt_mime_type' => $file->getMimeType(),
                'receipt_size' => $file->getSize(),
            ]);

            $this->audit('upload_receipt', $expense->id, [
                'receipt_name' => $file->getClientOriginalName(),
                'receipt_size' => $file->getSize(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Receipt uploaded successfully',
                'expense' => $expense,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Receipt upload failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to upload receipt', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download receipt file
     */
    public function downloadReceipt($id)
    {
        $expense = Expense::findOrFail($id);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt attached to this expense'], 404);
        }

        $filePath = storage_path('app/public/' . $expense->receipt_path);

        if (!file_exists($filePath)) {
            return response()->json(['message' => 'Receipt file not found'], 404);
        }

        return response()->download($filePath, $expense->receipt_original_name);
    }

    /**
     * Delete receipt file
     */
    public function deleteReceipt($id)
    {
        $expense = Expense::findOrFail($id);

        if (!$expense->receipt_path) {
            return response()->json(['message' => 'No receipt to delete'], 404);
        }

        try {
            DB::beginTransaction();

            // Delete file from storage
            Storage::disk('public')->delete($expense->receipt_path);

            // Update expense record
            $expense->update([
                'receipt_path' => null,
                'receipt_original_name' => null,
                'receipt_mime_type' => null,
                'receipt_size' => null,
            ]);

            $this->audit('delete_receipt', $expense->id, [
                'deleted_receipt' => $expense->receipt_original_name,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Receipt deleted successfully',
                'expense' => $expense,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Receipt deletion failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Failed to delete receipt', 'error' => $e->getMessage()], 500);
        }
    }
}
