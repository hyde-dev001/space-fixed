<?php

namespace App\Console\Commands;

use App\Models\HR\AttendanceRecord;
use App\Models\HR\OvertimeRequest;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoClockOutEmployees extends Command
{
    protected $signature = 'attendance:auto-clockout';

    protected $description = 'Automatically clock out employees at their recorded shop closing time';

    public function handle(): int
    {
        $timezone = config('app.shop_timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $count = 0;

        AttendanceRecord::query()->whereDate('date', '<=', $now->toDateString())
            ->whereNotNull('check_in_time')->whereNull('check_out_time')
            ->whereHas('employee', fn ($query) => $query->whereColumn('employees.shop_owner_id', 'attendance_records.shop_owner_id'))
            ->eachById(function (AttendanceRecord $candidate) use ($now, $timezone, &$count): void {
                $closed = DB::transaction(function () use ($candidate, $now, $timezone): bool {
                    $record = AttendanceRecord::query()->with('shopOwner')->lockForUpdate()->find($candidate->id);
                    if (! $record || $record->check_out_time || ! $record->check_in_time || ! $record->shopOwner) {
                        return false;
                    }
                    $date = $record->date->toDateString();
                    $day = strtolower($record->date->format('l'));
                    $closeTime = $record->expected_check_out?->format('H:i:s');
                    // Historical schedules must not be inferred from today's editable shop settings.
                    if (! $closeTime && $date === $now->toDateString()) {
                        $closeTime = $record->shopOwner->{$day.'_close'};
                    }
                    if (! $closeTime) {
                        $this->warn("Attendance #{$record->id} needs review: no saved closing time.");

                        return false;
                    }
                    $checkIn = Carbon::parse($date.' '.$record->check_in_time->format('H:i:s'), $timezone);
                    $closing = Carbon::parse($date.' '.$closeTime, $timezone);
                    $openTime = $record->expected_check_in?->format('H:i:s');
                    if ($openTime && $closing->lte(Carbon::parse($date.' '.$openTime, $timezone))) {
                        $closing->addDay();
                    }
                    $overtime = OvertimeRequest::query()->where('employee_id', $record->employee_id)
                        ->where('shop_owner_id', $record->shop_owner_id)->whereDate('overtime_date', $date)
                        ->whereIn('status', ['approved', 'assigned'])->lockForUpdate()->first();
                    if ($overtime && ($overtime->is_paid || $overtime->payroll_id)) {
                        $this->warn("Attendance #{$record->id} needs review: overtime is already linked to payroll.");

                        return false;
                    }
                    $checkout = $closing->copy();
                    $due = $closing->copy();
                    if ($overtime) {
                        $start = $closing->copy()->setTimeFromTimeString($overtime->start_time);
                        $end = $start->copy()->setTimeFromTimeString($overtime->end_time);
                        if ($end->lte($start)) {
                            $end->addDay();
                        }
                        if ($end->gt($closing)) {
                            $checkout = $end;
                            $due = $end->copy()->addMinutes(30);
                        }
                    }
                    if ($checkout->lt($checkIn)) {
                        $this->warn("Attendance #{$record->id} needs review: check-in is after the scheduled checkout.");

                        return false;
                    }
                    if ($now->lt($due)) {
                        return false;
                    }
                    $lunchStart = $record->lunch_break_start
                        ? Carbon::parse($date.' '.$record->lunch_break_start->format('H:i:s'), $timezone) : null;
                    $lunchEnd = $record->lunch_break_end
                        ? Carbon::parse($date.' '.$record->lunch_break_end->format('H:i:s'), $timezone) : null;
                    if ($lunchStart && $lunchEnd) {
                        if ($lunchStart->lt($checkIn) && $closing->toDateString() > $date) {
                            $lunchStart->addDay();
                            $lunchEnd->addDay();
                        }
                        if ($lunchEnd->lt($lunchStart)) {
                            $lunchEnd->addDay();
                        }
                    }
                    $regularEnd = $checkout->min($closing);
                    $regularHours = $this->workedMinutes($checkIn, $regularEnd, $lunchStart, $lunchEnd) / 60;
                    $overtimeStart = $overtime ? $checkIn->max($closing)->max($start) : $checkIn->max($closing);
                    $overtimeHours = $overtime && $checkout->gt($closing)
                        ? min(max(0, (float) $overtime->hours), $this->workedMinutes($overtimeStart, $checkout, $lunchStart, $lunchEnd) / 60) : 0;
                    $record->update([
                        'check_out_time' => $checkout->format('H:i:s'),
                        'working_hours' => round($regularHours, 2), 'overtime_hours' => round($overtimeHours, 2),
                        'auto_clocked_out' => true,
                        'auto_clockout_reason' => $checkout->gt($closing)
                            ? 'Auto clocked out at end of approved overtime period' : 'Auto clocked out at shop closing time',
                    ]);
                    if ($overtime && $checkout->gt($closing)) {
                        $overtime->update(['actual_hours' => round($overtimeHours, 2),
                            'actual_start_time' => $overtimeStart->format('H:i:s'), 'actual_end_time' => $checkout->format('H:i:s'),
                            'checked_out_at' => $checkout]);
                    }

                    return true;
                }, 3);
                $count += (int) $closed;
            });

        $this->info("Auto clock-out completed. {$count} employee(s) clocked out.");

        return self::SUCCESS;
    }

    private function workedMinutes(Carbon $start, Carbon $end, ?Carbon $lunchStart, ?Carbon $lunchEnd): float
    {
        $minutes = max(0, $start->diffInMinutes($end));
        if ($lunchStart && $lunchEnd) {
            $overlapStart = $start->max($lunchStart);
            $overlapEnd = $end->min($lunchEnd);
            if ($overlapEnd->gt($overlapStart)) {
                $minutes -= $overlapStart->diffInMinutes($overlapEnd);
            }
        }

        return max(0, $minutes);
    }
}
