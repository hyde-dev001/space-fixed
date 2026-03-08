<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\LeaveRequest;
use App\Models\ShopOwner;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MarkAbsentEmployees extends Command
{
    protected $signature = 'attendance:mark-absent
                            {--date= : Date to mark absences for (Y-m-d). Defaults to yesterday when run at midnight, or today when run manually.}
                            {--dry-run : Preview what would be marked without writing to the database}';

    protected $description = 'Mark active employees who had no attendance record on a working day as absent';

    public function handle(): int
    {
        // Default to yesterday so the nightly job (run just after midnight) marks the
        // completed working day.  Pass --date=today to run manually for today.
        $targetDate = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $today       = $targetDate->toDateString();
        $dayOfWeek   = strtolower($targetDate->format('l')); // monday, tuesday …
        $dryRun      = (bool) $this->option('dry-run');
        $markedCount = 0;

        $this->info(sprintf(
            '[%s] Running mark-absent for %s (%s)%s',
            now()->format('Y-m-d H:i:s'),
            $today,
            ucfirst($dayOfWeek),
            $dryRun ? ' [DRY RUN]' : '',
        ));

        $shopOwners = ShopOwner::all();

        foreach ($shopOwners as $shopOwner) {
            // ── 1. Is the shop open on this day? ────────────────────────
            $openField  = $dayOfWeek . '_open';
            $closeField = $dayOfWeek . '_close';

            if (empty($shopOwner->{$openField}) || empty($shopOwner->{$closeField})) {
                $this->line("  Shop #{$shopOwner->id} ({$shopOwner->business_name}): closed on {$dayOfWeek}, skipping.");
                continue;
            }

            $expectedCheckIn  = substr($shopOwner->{$openField},  0, 5); // HH:MM
            $expectedCheckOut = substr($shopOwner->{$closeField}, 0, 5);

            // ── 2. Get all active employees for this shop ────────────────
            $employees = Employee::where('shop_owner_id', $shopOwner->id)
                ->where('status', 'active')
                ->get();

            if ($employees->isEmpty()) {
                continue;
            }

            // Pre-load today's attendance records for this shop (one query)
            $presentIds = AttendanceRecord::where('shop_owner_id', $shopOwner->id)
                ->whereDate('date', $today)
                ->pluck('employee_id')
                ->toArray();

            // Pre-load employees on approved leave today (one query)
            $onLeaveIds = LeaveRequest::where('shop_owner_id', $shopOwner->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->pluck('employee_id')
                ->toArray();

            foreach ($employees as $employee) {
                // Already has a record (present, late, or even a previous absent record)
                if (in_array($employee->id, $presentIds)) {
                    continue;
                }

                // On approved leave → mark as on_leave, not absent
                if (in_array($employee->id, $onLeaveIds)) {
                    if ($dryRun) {
                        $this->line("  [DRY-RUN] Would mark Employee #{$employee->id} {$employee->first_name} {$employee->last_name} as ON_LEAVE on {$today}");
                    } else {
                        AttendanceRecord::create([
                            'employee_id'       => $employee->id,
                            'shop_owner_id'     => $shopOwner->id,
                            'date'              => $today,
                            'status'            => 'on_leave',
                            'expected_check_in'  => $expectedCheckIn,
                            'expected_check_out' => $expectedCheckOut,
                            'notes'             => 'Auto-marked: employee on approved leave',
                        ]);
                        $this->line("  Marked #{$employee->id} {$employee->first_name} {$employee->last_name} → ON_LEAVE");
                    }
                    $markedCount++;
                    continue;
                }

                // No record and not on leave → absent
                if ($dryRun) {
                    $this->line("  [DRY-RUN] Would mark Employee #{$employee->id} {$employee->first_name} {$employee->last_name} as ABSENT on {$today}");
                } else {
                    AttendanceRecord::create([
                        'employee_id'       => $employee->id,
                        'shop_owner_id'     => $shopOwner->id,
                        'date'              => $today,
                        'status'            => 'absent',
                        'expected_check_in'  => $expectedCheckIn,
                        'expected_check_out' => $expectedCheckOut,
                        'notes'             => 'Auto-marked: no clock-in recorded',
                    ]);
                    $this->line("  Marked #{$employee->id} {$employee->first_name} {$employee->last_name} → ABSENT");
                }
                $markedCount++;
            }
        }

        $this->info("Done. {$markedCount} record(s) created.");
        return 0;
    }
}
