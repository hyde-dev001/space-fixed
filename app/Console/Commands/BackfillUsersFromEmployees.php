<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUsersFromEmployees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:backfill-from-employees {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill users.first_name, last_name, phone, and address from employees table when missing';

    public function handle()
    {
        $dry = $this->option('dry-run');

        $this->info('Starting backfill of users from employees' . ($dry ? ' (dry run)' : ''));

        $rows = DB::table('employees')
            ->select('employees.email', 'employees.name', 'employees.phone', 'employees.address')
            ->join('users', 'users.email', '=', 'employees.email')
            ->where(function ($q) {
                $q->whereNull('users.first_name')->orWhere('users.first_name', '');
            })
            ->orWhere(function ($q) {
                $q->whereNull('users.last_name')->orWhere('users.last_name', '');
            })
            ->orWhere(function ($q) {
                $q->whereNull('users.phone')->orWhere('users.phone', '');
            })
            ->get();

        $this->info('Found ' . $rows->count() . ' users to update');

        foreach ($rows as $row) {
            $name = trim($row->name ?? '');
            $first = '';
            $last = '';
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name);
                $first = $parts[0] ?? '';
                $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
            }

            $updates = [];
            if ($first) $updates['first_name'] = $first;
            if ($last) $updates['last_name'] = $last;
            if (!empty($row->phone)) $updates['phone'] = $row->phone;
            if (!empty($row->address)) $updates['address'] = $row->address;

            if (empty($updates)) continue;

            $this->line("Updating user {$row->email} => " . json_encode($updates));

            if (!$dry) {
                DB::table('users')->where('email', $row->email)->update($updates);
            }
        }

        $this->info('Backfill complete');
        return 0;
    }
}
