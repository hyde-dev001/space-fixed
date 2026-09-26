<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmployeeClockedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = Auth::guard('user')->user();

        if (! $user instanceof User
            || ! $user->isEmployeeAccount()
            || $request->routeIs(
                'user.logout',
                'admin.logout',
                'staff.attendance.checkin',
                'erp.password.update',
                'erp.security.sessions.logout-others',
                'erp.security.totp.*',
                'erp.mfa.challenge.verify',
            )) {
            return $next($request);
        }

        $timezone = config('app.shop_timezone', 'Asia/Manila');
        $today = now($timezone)->toDateString();
        $employee = Employee::query()
            ->where('shop_owner_id', $user->shop_owner_id)
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
            ->first();

        $hasActiveAttendance = $employee !== null
            && AttendanceRecord::query()
                ->where('shop_owner_id', $user->shop_owner_id)
                ->where('employee_id', $employee->getKey())
                ->whereDate('date', $today)
                ->whereNotNull('check_in_time')
                ->whereNull('check_out_time')
                ->exists();

        if ($hasActiveAttendance) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'EMPLOYEE_NOT_CLOCKED_IN',
            'message' => 'Please clock in on the Time In page before processing business actions.',
        ], Response::HTTP_LOCKED);
    }
}
