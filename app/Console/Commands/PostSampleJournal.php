<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Finance\Account;
use App\Models\Finance\JournalEntry;
use App\Models\Finance\JournalLine;
use App\Models\AuditLog;

class PostSampleJournal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'finance:post-sample-journal {amount=1000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a sample balanced journal entry and post it, updating account balances';

    public function handle()
    {
        $amount = (float)$this->argument('amount');

        // pick two accounts: 1000 (asset) debit, 2000 (liability) credit
        $debitAccount = Account::firstWhere('code', '1000') ?? Account::create(['code' => '1000', 'name' => 'Cash', 'type' => 'Asset', 'normal_balance' => 'Debit']);
        $creditAccount = Account::firstWhere('code', '2000') ?? Account::create(['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'Liability', 'normal_balance' => 'Credit']);

        $this->info('Before balances:');
        $this->line($debitAccount->code . ' => ' . $debitAccount->balance);
        $this->line($creditAccount->code . ' => ' . $creditAccount->balance);

        // create draft entry
        $entry = JournalEntry::create([
            'reference' => 'CLI-SAMPLE-' . time(),
            'date' => now()->toDateString(),
            'description' => 'Sample CLI posted journal',
            'status' => 'draft',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $debitAccount->id,
            'account_code' => $debitAccount->code,
            'account_name' => $debitAccount->name,
            'debit' => $amount,
            'credit' => 0,
            'memo' => 'CLI debit',
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $creditAccount->id,
            'account_code' => $creditAccount->code,
            'account_name' => $creditAccount->name,
            'debit' => 0,
            'credit' => $amount,
            'memo' => 'CLI credit',
        ]);

        // post using same logic as controller
        DB::beginTransaction();
        try {
            $entry->load('lines');
            $totalDebit = $entry->lines->sum('debit');
            $totalCredit = $entry->lines->sum('credit');
            if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                $this->error('Entry not balanced');
                return 1;
            }

            $entry->status = 'posted';
            $entry->posted_by = 'cli';
            $entry->posted_at = now();
            $entry->save();

            foreach ($entry->lines as $line) {
                $account = Account::find($line->account_id);
                if (!$account) continue;
                $normal = strtolower($account->normal_balance ?? 'debit');
                if ($normal === 'credit') {
                    $delta = ($line->credit - $line->debit);
                } else {
                    $delta = ($line->debit - $line->credit);
                }
                $account->balance = bcadd((string)$account->balance, (string)$delta, 2);
                $account->save();
            }

            // AuditLog table expects shop_owner_id and actor_user_id in this project migration
            $shopOwnerId = \App\Models\ShopOwner::first()?->id ?? 1;
            $actorUserId = \App\Models\User::first()?->id ?? 1;
            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'actor_user_id' => $actorUserId,
                'action' => 'post_journal_cli',
                'target_type' => 'journal_entry',
                'target_id' => $entry->id,
                'metadata' => ['posted_by' => 'cli']
            ]);

            DB::commit();

            // reload accounts
            $debitAccount->refresh();
            $creditAccount->refresh();

            $this->info('Posted entry id: ' . $entry->id);
            $this->info('After balances:');
            $this->line($debitAccount->code . ' => ' . $debitAccount->balance);
            $this->line($creditAccount->code . ' => ' . $creditAccount->balance);

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Failed: ' . $e->getMessage());
            return 1;
        }
    }
}
