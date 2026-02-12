<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AttendanceRecord;
use App\Models\ShopOwner;
use App\Models\HR\OvertimeRequest;
use Carbon\Carbon;

class AutoClockOutEmployees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:auto-clockout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically clock out employees at shop closing time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');
        $dayOfWeek = strtolower($now->format('l')); // monday, tuesday, etc.
        
        $this->info("Running auto clock-out check at {$now->format('Y-m-d H:i:s')}");
        
        // Get all shop owners and their closing times
        $shopOwners = ShopOwner::all();
        $clockedOutCount = 0;
        
        foreach ($shopOwners as $shopOwner) {
            $closeField = $dayOfWeek . '_close';
            $shopCloseTime = $shopOwner->{$closeField};
            
            if (!$shopCloseTime) {
                continue; // Shop is closed today or no closing time set
            }
            
            // Parse closing time (e.g., "21:00:00" or "21:00")
            $closeTime = substr($shopCloseTime, 0, 8); // Ensure HH:MM:SS format
            
            // Check if current time has passed closing time
            if ($currentTime >= $closeTime) {
                // Find all employees still clocked in for this shop
                $openAttendances = AttendanceRecord::where('shop_owner_id', $shopOwner->id)
                    ->whereDate('date', $today)
                    ->whereNotNull('check_in_time')
                    ->whereNull('check_out_time')
                    ->get();
                
                foreach ($openAttendances as $attendance) {
                    // Check if employee has approved or assigned overtime for today
                    $approvedOvertime = OvertimeRequest::where('employee_id', $attendance->employee_id)
                        ->where('shop_owner_id', $shopOwner->id)
                        ->where('overtime_date', $today)
                        ->whereIn('status', ['approved', 'assigned'])
                        ->first();
                    
                    if ($approvedOvertime) {
                        // Employee has approved overtime - check if overtime period has ended
                        $overtimeEndTime = $approvedOvertime->end_time; // e.g., "19:00:00"
                        $gracePeriodMinutes = 30; // 30 minute grace period
                        
                        // Add grace period to overtime end time
                        $overtimeEndWithGrace = Carbon::parse($overtimeEndTime)->addMinutes($gracePeriodMinutes)->format('H:i:s');
                        
                        if ($currentTime >= $overtimeEndWithGrace) {
                            // Overtime period (+ grace) has ended, auto clock-out
                            $attendance->update([
                                'check_out_time' => $overtimeEndTime, // Clock out at approved overtime end time
                                'auto_clocked_out' => true,
                                'auto_clockout_reason' => 'Auto clocked out at end of approved overtime period',
                            ]);
                            
                            // Calculate working hours
                            $checkIn = Carbon::parse($attendance->check_in_time);
                            $checkOut = Carbon::parse($overtimeEndTime);
                            $workingMinutes = $checkIn->diffInMinutes($checkOut);
                            $workingHours = floor($workingMinutes / 60);
                            
                            $attendance->update([
                                'working_hours' => $workingHours,
                            ]);
                            
                            $clockedOutCount++;
                            $this->info("Auto clocked out Employee ID {$attendance->employee_id} at {$overtimeEndTime} (overtime end)");
                        } else {
                            // Still within overtime period + grace, skip
                            $this->info("Skipped Employee ID {$attendance->employee_id} - Within overtime period (ends at {$overtimeEndTime} + {$gracePeriodMinutes}min grace)");
                        }
                        continue;
                    }
                    
                    // No approved overtime - auto clock-out at shop closing time
                    $attendance->update([
                        'check_out_time' => $closeTime,
                        'auto_clocked_out' => true,
                        'auto_clockout_reason' => 'Auto clocked out at shop closing time',
                    ]);
                    
                    // Calculate working hours
                    $checkIn = Carbon::parse($attendance->check_in_time);
                    $checkOut = Carbon::parse($closeTime);
                    $workingMinutes = $checkIn->diffInMinutes($checkOut);
                    $workingHours = floor($workingMinutes / 60);
                    
                    $attendance->update([
                        'working_hours' => $workingHours,
                    ]);
                    
                    $clockedOutCount++;
                    $this->info("Auto clocked out Employee ID {$attendance->employee_id} at {$closeTime}");
                }
            }
        }
        
        $this->info("Auto clock-out completed. {$clockedOutCount} employee(s) clocked out.");
        
        return 0;
    }
}
