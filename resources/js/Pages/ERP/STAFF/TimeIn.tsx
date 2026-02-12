import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import { useState, useEffect } from 'react';
import Swal from 'sweetalert2';

const ClockIcon = () => (
    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const CheckIcon = () => (
    <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
    </svg>
);

const CalendarIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </svg>
);

const TrendingUpIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
    </svg>
);

const CoffeeIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
    </svg>
);

const LeaveIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
    </svg>
);

const OvertimeIcon = () => (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

interface AttendanceRecord {
    date: string;
    clockIn: string;
    clockOut: string;
    totalHours: string;
    status: string;
    isLate?: boolean;
    minutesLate?: number;
    expectedCheckIn?: string;
    isEarlyDeparture?: boolean;
    minutesEarlyDeparture?: number;
    autoClockedOut?: boolean;
    autoClockoutReason?: string;
}

// Helper function to get Philippines time (UTC+8)
const getPHTime = () => {
    return new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" }));
};

export default function TimeIn() {
    const { auth } = usePage().props as any;
    const [currentTime, setCurrentTime] = useState<Date>(getPHTime());
    const [loginTime, setLoginTime] = useState<Date | null>(null);
    const [lunchStartTime, setLunchStartTime] = useState<Date | null>(null);
    const [lunchEndTime, setLunchEndTime] = useState<Date | null>(null);
    const [logoutTime, setLogoutTime] = useState<Date | null>(null);
    const [isClockedIn, setIsClockedIn] = useState(false);
    const [isOnLunch, setIsOnLunch] = useState(false);
    const [totalHours, setTotalHours] = useState<string>('0:00:00');
    const [attendanceRecords, setAttendanceRecords] = useState<AttendanceRecord[]>([]);
    const [showLeaveModal, setShowLeaveModal] = useState(false);
    const [leaveMonth, setLeaveMonth] = useState('');
    const [leaveDay, setLeaveDay] = useState('');
    const [leaveReason, setLeaveReason] = useState('');
    const [lastLeaveRequestTime, setLastLeaveRequestTime] = useState<number | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [todayAttendance, setTodayAttendance] = useState<any>(null);
    const [showOvertimeModal, setShowOvertimeModal] = useState(false);
    const [overtimeHours, setOvertimeHours] = useState('');
    const [overtimeReason, setOvertimeReason] = useState('');
    const [overtimeCustomReason, setOvertimeCustomReason] = useState('');
    const [shopHours, setShopHours] = useState<{open: string, close: string} | null>(null);
    const [latenessStats, setLatenessStats] = useState<any>(null);
    const [todayOvertimeRequests, setTodayOvertimeRequests] = useState<any[]>([]);
    const [activeOvertimeId, setActiveOvertimeId] = useState<number | null>(null);
    const [isOvertimeLoading, setIsOvertimeLoading] = useState(false);
    const [processingOvertimeId, setProcessingOvertimeId] = useState<number | null>(null);

    // Check attendance status on component mount
    useEffect(() => {
        checkAttendanceStatus();
        fetchAttendanceRecords();
        fetchShopHours();
        fetchLatenessStats();
        fetchTodayOvertimeRequests();
    }, []);

    // Load last leave request time from localStorage
    useEffect(() => {
        const savedTime = localStorage.getItem('lastLeaveRequestTime');
        if (savedTime) {
            setLastLeaveRequestTime(parseInt(savedTime));
        }
    }, []);

    const checkAttendanceStatus = async () => {
        try {
            const response = await fetch('/api/staff/attendance/status', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

            if (response.ok) {
                const data = await response.json();
                setTodayAttendance(data);
                
                if (data.checked_in && data.check_in_time) {
                    const [hours, minutes] = data.check_in_time.split(':');
                    const checkInDate = new Date();
                    checkInDate.setHours(parseInt(hours), parseInt(minutes), 0);
                    setLoginTime(checkInDate);
                    setIsClockedIn(true);
                }

                if (data.checked_out && data.check_out_time) {
                    const [hours, minutes] = data.check_out_time.split(':');
                    const checkOutDate = new Date();
                    checkOutDate.setHours(parseInt(hours), parseInt(minutes), 0);
                    setLogoutTime(checkOutDate);
                    setIsClockedIn(false);
                }
                
                // SEAMLESS OVERTIME: Auto-detect if overtime is active
                if (data.has_approved_overtime && data.overtime_id) {
                    setActiveOvertimeId(data.overtime_id);
                }
            }
        } catch (error) {
            console.error('Error checking attendance status:', error);
        }
    };

    const fetchAttendanceRecords = async () => {
        try {
            const response = await fetch('/api/staff/attendance/my-records', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

                if (response.ok) {
                const result = await response.json();
                const records = result.data || [];
                
                const formattedRecords = records.map((record: any) => ({
                    date: new Date(record.date).toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric' 
                    }),
                    clockIn: record.check_in_time ? formatTimeFromString(record.check_in_time) : '--:--',
                    clockOut: record.check_out_time ? formatTimeFromString(record.check_out_time) : '--:--',
                    totalHours: record.working_hours ? `${record.working_hours}:00` : '0:00:00',
                    status: record.check_out_time ? 'Completed' : 'In Progress',
                    isLate: record.is_late || false,
                    minutesLate: record.minutes_late || 0,
                    expectedCheckIn: record.expected_check_in ? formatTimeFromString(record.expected_check_in) : null,
                    isEarlyDeparture: record.is_early_departure || false,
                    minutesEarlyDeparture: record.minutes_early_departure || 0,
                    autoClockedOut: record.auto_clocked_out || false,
                    autoClockoutReason: record.auto_clockout_reason || null
                }));

                setAttendanceRecords(formattedRecords);
            }
        } catch (error) {
            console.error('Error fetching attendance records:', error);
        }
    };

    const fetchShopHours = async () => {
        try {
            const response = await fetch(`/api/staff/shop-hours/today`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });
            if (response.ok) {
                const data = await response.json();
                console.log('Shop hours response:', data);
                setShopHours(data);
            } else {
                console.error('Failed to fetch shop hours:', response.status);
            }
        } catch (error) {
            console.error('Error fetching shop hours:', error);
        }
    };

    const fetchLatenessStats = async () => {
        try {
            const response = await fetch('/api/staff/attendance/my-lateness-stats', {
                credentials: 'include',
            });
            if (response.ok) {
                const data = await response.json();
                setLatenessStats(data);
            }
        } catch (error) {
            console.error('Error fetching lateness stats:', error);
        }
    };

    const fetchTodayOvertimeRequests = async () => {
        try {
            const response = await fetch('/api/staff/overtime/today-approved', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });
                if (response.ok) {
                const result = await response.json();
                // Normalize overtime times to 12-hour display for the UI
                const normalized = (result.data || []).map((ot: any) => ({
                    ...ot,
                    start_time: ot.start_time,
                    end_time: ot.end_time,
                    actual_start_time: ot.actual_start_time,
                    actual_end_time: ot.actual_end_time
                }));
                setTodayOvertimeRequests(normalized);
                
                // Check if any overtime is currently active (checked in but not checked out)
                const activeOvertime = (result.data || []).find((ot: any) => ot.checked_in_at && !ot.checked_out_at);
                if (activeOvertime) {
                    setActiveOvertimeId(activeOvertime.id);
                } else {
                    // Reset active overtime if none is currently active
                    setActiveOvertimeId(null);
                }
            }
        } catch (error) {
            console.error('Error fetching today overtime requests:', error);
        }
    };

    const handleOvertimeCheckIn = async (overtimeId: number) => {
        // Prevent spam clicks and multiple simultaneous requests
        if (isOvertimeLoading || processingOvertimeId !== null || activeOvertimeId !== null) return;
        
        setIsOvertimeLoading(true);
        setProcessingOvertimeId(overtimeId);
        try {
            const response = await fetch(`/api/staff/overtime/${overtimeId}/check-in`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

                if (response.ok) {
                // Set active overtime immediately to show indicator
                setActiveOvertimeId(overtimeId);
                
                // Show success message
                await Swal.fire({
                    icon: 'success',
                    title: 'Overtime Started',
                    text: 'You have successfully clocked in for overtime.',
                    timer: 2000,
                    showConfirmButton: false,
                });
                
                // Refresh data after Swal closes
                await fetchTodayOvertimeRequests();
                } else {
                const error = await response.json();
                
                // Show detailed error message for time validation
                if (error.scheduled_time && error.current_time) {
                    await Swal.fire({
                        icon: 'error',
                        title: error.error || 'Clock In Failed',
                        html: `
                            <div class="text-left">
                                <p class="mb-2">${error.message || 'Failed to clock in for overtime.'}</p>
                                <p class="text-sm text-gray-600">Scheduled: <strong>${formatTimeFromString(error.scheduled_time)}</strong></p>
                                <p class="text-sm text-gray-600">Current Time: <strong>${formatTimeFromString(error.current_time)}</strong></p>
                            </div>
                        `,
                    });
                } else {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Clock In Failed',
                        text: error.error || error.message || 'Failed to clock in for overtime.',
                    });
                }
            }
        } catch (error: any) {
            console.error('Error clocking in for overtime:', error);
            await Swal.fire({
                icon: 'error',
                title: 'Error',
                text: error.message || 'An error occurred while clocking in for overtime.',
            });
        } finally {
            setIsOvertimeLoading(false);
            setProcessingOvertimeId(null);
        }
    };

    const handleOvertimeCheckOut = async (overtimeId: number) => {
        // Prevent spam clicks and ensure only active overtime can be checked out
        if (isOvertimeLoading || processingOvertimeId !== null || activeOvertimeId !== overtimeId) return;
        
        setIsOvertimeLoading(true);
        setProcessingOvertimeId(overtimeId);
        try {
            const response = await fetch(`/api/staff/overtime/${overtimeId}/check-out`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

            if (response.ok) {
                const result = await response.json();
                setActiveOvertimeId(null);
                await fetchTodayOvertimeRequests();
                Swal.fire({
                    icon: 'success',
                    title: 'Overtime Completed',
                    html: `<p>You have successfully clocked out from overtime.</p><p class="mt-2 text-sm text-gray-600">Actual hours worked: <strong>${result.actual_hours} hours</strong></p>`,
                    timer: 3000,
                    showConfirmButton: false,
                });
            } else {
                const error = await response.json();
                Swal.fire({
                    icon: 'error',
                    title: 'Clock Out Failed',
                    text: error.error || 'Failed to clock out from overtime.',
                });
            }
        } catch (error) {
            console.error('Error clocking out from overtime:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while clocking out from overtime.',
            });
        } finally {
            setIsOvertimeLoading(false);
            setProcessingOvertimeId(null);
        }
    };

    const formatTime = (date: Date | null) => {
        if (!date) return '--:--:--';
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    const formatTimeShort = (date: Date | null) => {
        if (!date) return '--:--';
        return date.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    // Convert HH:mm[:ss] (24-hour) strings to 12-hour format like "h:mm AM/PM"
    const formatTimeFromString = (timeStr?: string | null) => {
        if (!timeStr) return '--:--';
        // Accept formats like "17:00:00", "17:00" or "17:00:00.000000"
        const parts = timeStr.split(':');
        if (parts.length < 2) return timeStr;
        let hour = parseInt(parts[0], 10);
        const minute = parts[1].slice(0,2);
        if (Number.isNaN(hour)) return timeStr;
        const ampm = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12;
        if (hour === 0) hour = 12;
        return `${hour}:${minute} ${ampm}`;
    };

    useEffect(() => {
        const updateTime = () => setCurrentTime(getPHTime());
        updateTime();
        const interval = setInterval(updateTime, 1000);
        return () => clearInterval(interval);
    }, []);

    useEffect(() => {
        if (loginTime && !logoutTime) {
            const interval = setInterval(() => {
                calculateTotalHours();
            }, 1000);
            return () => clearInterval(interval);
        }
    }, [loginTime, logoutTime, lunchStartTime, lunchEndTime]);

    const calculateTotalHours = () => {
        if (!loginTime) return;

        const endTime = logoutTime ?? currentTime;
        if (!endTime) return;

        let totalSeconds = Math.floor((endTime.getTime() - loginTime.getTime()) / 1000);

        if (lunchStartTime && lunchEndTime) {
            const lunchSeconds = Math.floor((lunchEndTime.getTime() - lunchStartTime.getTime()) / 1000);
            totalSeconds -= lunchSeconds;
        }

        totalSeconds = Math.max(0, totalSeconds);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = Math.floor(totalSeconds % 60);
        setTotalHours(`${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`);
    };

    const handleClockIn = async () => {
        // Check if shop is open
        if (!shopHours || !shopHours.open || !shopHours.close) {
            await Swal.fire({
                icon: 'error',
                title: 'Shop Closed',
                text: 'The shop is currently closed. You cannot clock in at this time.',
                confirmButtonColor: '#ef4444'
            });
            return;
        }

        // Parse shop hours and current time
        const now = getPHTime();
        const currentTime = now.getHours() * 60 + now.getMinutes();
        
        // Parse shop open/close times (format: "HH:MM:SS" or "HH:MM")
        const [openHour, openMinute] = shopHours.open.split(':').map(Number);
        const [closeHour, closeMinute] = shopHours.close.split(':').map(Number);
        const shopOpenTime = openHour * 60 + openMinute;
        const shopCloseTime = closeHour * 60 + closeMinute;
        
        // Allow check-in 30 minutes before opening (grace period)
        const gracePeriod = 30;
        const earliestCheckIn = shopOpenTime - gracePeriod;
        
        // Check if current time is before allowed time or after closing
        if (currentTime < earliestCheckIn || currentTime > shopCloseTime) {
            const openTimeDisplay = formatTimeFromString(shopHours.open);
            const closeTimeDisplay = formatTimeFromString(shopHours.close);
            
            await Swal.fire({
                icon: 'warning',
                title: 'Outside Shop Hours',
                html: `
                    <div class="text-left">
                        <p class="mb-2">You cannot clock in at this time.</p>
                        <p class="text-sm text-gray-600">Shop Hours: <strong>${openTimeDisplay} - ${closeTimeDisplay}</strong></p>
                        <p class="text-sm text-gray-600">Current Time: <strong>${formatTimeShort(now)}</strong></p>
                        <p class="text-sm text-amber-600 mt-2">⏰ You can clock in starting 30 minutes before opening time.</p>
                    </div>
                `,
                confirmButtonColor: '#ef4444'
            });
            return;
        }

        setIsLoading(true);
        try {
            const response = await fetch('/api/staff/attendance/check-in', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

            const data = await response.json();

            if (response.ok) {
                const now = new Date();
                setLoginTime(now);
                setIsClockedIn(true);
                setLogoutTime(null);
                
                // Check if early check-in (arrived early, ask for reason)
                    if (data.attendance?.is_early && data.attendance?.minutes_early > 0) {
                    const { value: reason } = await Swal.fire({
                        icon: 'info',
                        title: `Early Check-In`,
                        html: `<div class="text-left">
                            <p class="mb-2">You are <strong class="text-blue-600">${data.attendance.minutes_early} minutes early</strong></p>
                            <p class="text-sm text-gray-600">Expected: ${formatTimeFromString(data.attendance.expected_check_in)}</p>
                            <p class="text-sm text-gray-600">Actual: ${formatTimeFromString(data.attendance.check_in_time)}</p>
                            <p class="text-sm text-amber-600 mt-2">⏰ This is within the allowed grace period (30 minutes before opening)</p>
                        </div>`,
                        input: 'textarea',
                        inputLabel: 'Please provide a reason (optional)',
                        inputPlaceholder: 'Opening duties, inventory, special assignment...',
                        showCancelButton: false,
                        confirmButtonText: 'Submit',
                        confirmButtonColor: '#3b82f6'
                    });

                    if (reason) {
                        await fetch(`/api/staff/attendance/${data.attendance.id}/add-early-reason`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            },
                            body: JSON.stringify({ early_reason: reason }),
                            credentials: 'include',
                        });
                    }
                }
                // Check if late and handle accordingly
                else if (data.attendance?.is_late) {
                    const { value: reason } = await Swal.fire({
                        icon: 'warning',
                        title: `Late Check-In`,
                        html: `<div class="text-left">
                            <p class="mb-2">You are <strong class="text-red-600">${data.attendance.minutes_late} minutes late</strong></p>
                            <p class="text-sm text-gray-600">Expected: ${data.attendance.expected_check_in}</p>
                            <p class="text-sm text-gray-600">Actual: ${data.attendance.check_in_time}</p>
                        </div>`,
                        input: 'textarea',
                        inputLabel: 'Please provide a reason (optional)',
                        inputPlaceholder: 'Traffic, family emergency, etc...',
                        showCancelButton: false,
                        confirmButtonText: 'Submit',
                        confirmButtonColor: '#f59e0b'
                    });

                    if (reason) {
                        await fetch(`/api/staff/attendance/${data.attendance.id}/add-lateness-reason`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                            },
                            body: JSON.stringify({ lateness_reason: reason }),
                            credentials: 'include',
                        });
                    }
                } else {
                    await Swal.fire({
                        icon: 'success',
                        title: 'On Time! ✓',
                        text: 'You checked in on time. Great job!',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }

                await checkAttendanceStatus();
                await fetchAttendanceRecords();
                await fetchLatenessStats();
            } else {
                // Handle "too early" error (more than 30 minutes before opening)
                if (data.error === 'Too early to check in') {
                    await Swal.fire({
                        icon: 'warning',
                        title: 'Too Early to Check In',
                        html: `<div class="text-left">
                            <p class="mb-2"><strong>Shop opens at ${formatTimeFromString(data.shop_open_time)}</strong></p>
                            <p class="text-sm text-gray-600">You can check in starting from: <strong>${formatTimeFromString(data.earliest_check_in)}</strong></p>
                            <p class="text-sm text-amber-600 mt-2">⏰ Please wait ${data.minutes_too_early} more minutes</p>
                        </div>`,
                        confirmButtonColor: '#3b82f6',
                    });
                } else {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Check-In Failed',
                        text: data.error || data.message || 'Failed to clock in. Please try again.',
                    });
                }
            }
        } catch (error) {
            console.error('Error clocking in:', error);
            await Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while clocking in. Please try again.',
            });
        } finally {
            setIsLoading(false);
        }
    };

    const handleStartLunch = () => {
        const now = new Date();
        setLunchStartTime(now);
        setIsOnLunch(true);
    };

    const handleEndLunch = () => {
        const now = new Date();
        setLunchEndTime(now);
        setIsOnLunch(false);
        setTimeout(() => {
            setLunchStartTime(null);
            setLunchEndTime(null);
        }, 100);
    };

    const handleClockOut = async () => {
        if (!loginTime) return;

        // Show confirmation dialog
        const result = await Swal.fire({
            icon: 'question',
            title: 'Clock Out?',
            html: `
                <div class="text-left">
                    <p class="mb-2">Are you sure you want to clock out?</p>
                    <p class="text-sm text-gray-600">Login Time: ${formatTimeShort(loginTime)}</p>
                    <p class="text-sm text-gray-600">Current Time: ${formatTimeShort(new Date())}</p>
                    <p class="text-sm font-semibold text-blue-600 mt-2">Total Hours: ${totalHours}</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, Clock Out',
            cancelButtonText: 'Cancel'
        });

        if (!result.isConfirmed) return;

        setIsLoading(true);
        try {
            const response = await fetch('/api/staff/attendance/check-out', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                credentials: 'include',
            });

            const data = await response.json();

            if (response.ok) {
                const now = new Date();
                setLogoutTime(now);
                setIsClockedIn(false);
                setIsOnLunch(false);
                
                const workingHours = data.attendance?.working_hours || 0;
                setTotalHours(`${Math.floor(workingHours)}:${Math.round((workingHours % 1) * 60).toString().padStart(2, '0')}:00`);

                await Swal.fire({
                    icon: 'success',
                    title: 'Checked Out!',
                    text: `You have successfully clocked out. Total working hours: ${workingHours} hours`,
                    timer: 3000,
                    showConfirmButton: false
                });

                await fetchAttendanceRecords();

                setTimeout(() => {
                    setLoginTime(null);
                    setLunchStartTime(null);
                    setLunchEndTime(null);
                    setLogoutTime(null);
                }, 1000);
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: 'Check-Out Failed',
                    text: data.error || 'Failed to clock out. Please try again.',
                });
            }
        } catch (error) {
            console.error('Error clocking out:', error);
            await Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while clocking out. Please try again.',
            });
        } finally {
            setIsLoading(false);
        }
    };

    const handleRequestLeaveClick = () => {
        setShowLeaveModal(true);
    };

    const handleLeaveRequest = async () => {
        if (!leaveMonth || !leaveDay || !leaveReason) {
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Form',
                text: 'Please fill in all fields',
                confirmButtonColor: '#9333ea'
            });
            return;
        }

        // Parse the month and day to create start_date and end_date
        const year = new Date().getFullYear();
        const monthNumber = new Date(Date.parse(leaveMonth + " 1, 2000")).getMonth() + 1;
        const dayNumber = parseInt(leaveDay);
        const startDate = `${year}-${String(monthNumber).padStart(2, '0')}-${String(dayNumber).padStart(2, '0')}`;
        const endDate = startDate; // Single day leave
        
        const result = await Swal.fire({
            title: 'Submit Leave Request?',
            html: `<div class="text-left">
                <p><strong>Date:</strong> ${leaveMonth} ${leaveDay}, ${year}</p>
                <p><strong>Reason:</strong> ${leaveReason}</p>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#9333ea',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, submit it!',
            cancelButtonText: 'Cancel'
        });

        if (result.isConfirmed) {
            setIsLoading(true);
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch('/api/staff/leave/request', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        leave_type: 'personal',
                        start_date: startDate,
                        end_date: endDate,
                        reason: leaveReason,
                        is_half_day: false,
                    }),
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.error || errorData.message || 'Failed to submit leave request');
                }

                const data = await response.json();
                
                setLeaveMonth('');
                setLeaveDay('');
                setLeaveReason('');
                setShowLeaveModal(false);
                
                await Swal.fire({
                    icon: 'success',
                    title: 'Request Submitted!',
                    html: '<p>Your leave request has been submitted successfully and is now under review by the Human Resources department.</p><p class="text-sm text-gray-600 mt-2">You will be notified once your request has been processed.</p>',
                    confirmButtonColor: '#9333ea',
                    timer: 3000
                });
            } catch (error: any) {
                console.error('Leave request error:', error);
                await Swal.fire({
                    icon: 'error',
                    title: 'Submission Failed',
                    text: error.message || 'An error occurred while submitting your leave request. Please try again.',
                    confirmButtonColor: '#9333ea'
                });
            } finally {
                setIsLoading(false);
            }
        }
    };

    const handleOvertimeClick = () => {
        setShowOvertimeModal(true);
    };

    const handleOvertimeRequest = async () => {
        if (isLoading) return; // Prevent spam
        
        if (!overtimeHours || !overtimeReason) {
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Form',
                text: 'Please fill in all required fields',
            });
            return;
        }

        if (overtimeReason === 'Others' && !overtimeCustomReason) {
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete Form',
                text: 'Please provide a reason for overtime',
            });
            return;
        }

        setIsLoading(true);
        try {
            const finalReason = overtimeReason === 'Others' ? overtimeCustomReason : overtimeReason;
            
            const response = await fetch('/api/staff/overtime/request', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    hours: overtimeHours,
                    reason: finalReason,
                }),
                credentials: 'include',
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || errorData.message || 'Failed to submit overtime request');
            }

            const data = await response.json();
            
            setOvertimeHours('');
            setOvertimeReason('');
            setOvertimeCustomReason('');
            setShowOvertimeModal(false);
            
            await Swal.fire({
                icon: 'success',
                title: 'Request Submitted!',
                html: '<p>Your overtime request has been submitted successfully and is now pending approval.</p><p class="text-sm text-gray-600 mt-2">You will be notified once your request has been reviewed.</p>',
                confirmButtonColor: '#3b82f6',
                timer: 3000
            });
        } catch (error: any) {
            console.error('Overtime request error:', error);
            await Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                text: error.message || 'An error occurred while submitting your overtime request. Please try again.',
                confirmButtonColor: '#3b82f6'
            });
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <AppLayoutERP>
            <Head title="Attendance - Time In/Out" />
            
            <div className="min-h-screen">
                {!showOvertimeModal && !showLeaveModal ? (
                <>
                {/* Header Section */}
                <div className="mb-12">
                    <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-3">
                            <div className="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/30">
                                <ClockIcon />
                            </div>
                            <h1 className="text-4xl font-bold text-gray-900 dark:text-white">
                                Attendance Tracking
                            </h1>
                        </div>
                        <div className="flex items-center gap-3">
                            <button
                                onClick={handleOvertimeClick}
                                disabled={todayOvertimeRequests.length > 0}
                                className="rounded-lg border border-blue-600 bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-blue-700 hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-blue-600 flex items-center gap-2"
                                title={todayOvertimeRequests.length > 0 ? 'You already have overtime today' : 'Request overtime'}
                            >
                                <OvertimeIcon />
                                Over Time
                            </button>
                            <button
                                onClick={handleRequestLeaveClick}
                                className="rounded-lg border border-blue-600 bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-blue-700 hover:shadow-md flex items-center gap-2"
                            >
                                <LeaveIcon />
                                Request Leave
                            </button>
                        </div>
                    </div>
                    <p className="text-lg text-gray-600 dark:text-gray-400 mt-2">
                        Manage your daily work hours efficiently
                    </p>
                </div>

                {/* Main Clock Section */}
                <div className="mb-12">
                    <div className="relative overflow-hidden rounded-3xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 p-0 shadow-xl">
                        <div className="relative p-12 md:p-16">
                            <div className="text-center">
                                <div className="mb-8">
                                    <p className="text-gray-600 dark:text-gray-400 text-sm font-semibold uppercase tracking-widest mb-4">
                                        Current Time
                                    </p>
                                    <div className={`text-6xl md:text-7xl font-bold font-mono tracking-tight mb-4 ${
                                        isClockedIn 
                                            ? todayAttendance?.is_late 
                                                ? 'text-red-600 dark:text-red-400' 
                                                : 'text-green-600 dark:text-green-400'
                                            : 'text-gray-900 dark:text-white'
                                    }`}>
                                        {formatTime(currentTime)}
                                    </div>
                                    {shopHours ? (
                                        shopHours.is_open ? (
                                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                                Shop Hours Today: <span className="font-semibold text-green-600 dark:text-green-400">{formatTimeFromString(shopHours.open)} - {formatTimeFromString(shopHours.close)}</span>
                                            </p>
                                        ) : (
                                            <p className="text-sm text-red-600 dark:text-red-400 font-semibold">
                                                🔴 Shop Closed Today ({shopHours.day})
                                            </p>
                                        )
                                    ) : (
                                        <p className="text-sm text-gray-400 dark:text-gray-500">
                                            Loading shop hours...
                                        </p>
                                    )}
                                    <p className="text-gray-600 dark:text-gray-400 text-lg">
                                        {new Date().toLocaleDateString('en-US', { 
                                            weekday: 'long', 
                                            month: 'long', 
                                            day: 'numeric',
                                            year: 'numeric'
                                        })}
                                    </p>
                                </div>

                                <div className="mb-8 flex justify-center">
                                    <div className={`flex items-center gap-2 px-6 py-3 rounded-full transition-all duration-300 ${
                                        isOnLunch
                                            ? 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border border-orange-300 dark:border-orange-700'
                                            : isClockedIn 
                                            ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-300 dark:border-green-700' 
                                            : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-300 dark:border-red-700'
                                    }`}>
                                        <div className={`w-3 h-3 rounded-full animate-pulse ${
                                            isOnLunch ? 'bg-orange-500' : isClockedIn ? 'bg-green-500' : 'bg-red-500'
                                        }`} />
                                        <span className="font-semibold text-sm uppercase tracking-wide">
                                            {isOnLunch ? 'On Lunch Break' : isClockedIn ? 'Clocked In' : 'Clocked Out'}
                                        </span>
                                    </div>
                                </div>

                                <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                                    <button
                                        onClick={handleClockIn}
                                        disabled={isClockedIn || isLoading}
                                        className={`px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 active:scale-95 shadow-lg ${
                                            isClockedIn || isLoading
                                                ? 'bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-600 cursor-not-allowed'
                                                : 'bg-green-500 text-white hover:bg-green-600 hover:shadow-xl'
                                        }`}
                                    >
                                        <div className="flex items-center justify-center gap-2">
                                            {isLoading ? (
                                                <>
                                                    <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                    </svg>
                                                    Processing...
                                                </>
                                            ) : (
                                                <>
                                                    <CheckIcon />
                                                    Clock In
                                                </>
                                            )}
                                        </div>
                                    </button>

                                    {isClockedIn && !isOnLunch && (
                                        <button
                                            onClick={handleStartLunch}
                                            className="px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 active:scale-95 shadow-lg bg-orange-500 text-white hover:bg-orange-600 hover:shadow-xl"
                                        >
                                            <div className="flex items-center justify-center gap-2">
                                                <CoffeeIcon />
                                                Start Lunch
                                            </div>
                                        </button>
                                    )}

                                    {isOnLunch && (
                                        <button
                                            onClick={handleEndLunch}
                                            className="px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 active:scale-95 shadow-lg bg-blue-500 text-white hover:bg-blue-600 hover:shadow-xl"
                                        >
                                            <div className="flex items-center justify-center gap-2">
                                                <CheckIcon />
                                                End Lunch
                                            </div>
                                        </button>
                                    )}

                                    <button
                                        onClick={handleClockOut}
                                        disabled={!isClockedIn || isOnLunch || isLoading}
                                        className={`px-8 py-4 rounded-2xl font-bold text-lg transition-all duration-300 transform hover:scale-105 active:scale-95 shadow-lg ${
                                            !isClockedIn || isOnLunch || isLoading
                                                ? 'bg-gray-200 dark:bg-gray-800 text-gray-400 dark:text-gray-600 cursor-not-allowed'
                                                : 'bg-red-500 text-white hover:bg-red-600 hover:shadow-xl'
                                        }`}
                                    >
                                        {isLoading ? (
                                            <div className="flex items-center justify-center gap-2">
                                                <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                </svg>
                                                Processing...
                                            </div>
                                        ) : (
                                            'Clock Out'
                                        )}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mb-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 p-6 shadow-sm">
                        <div className="flex items-center gap-3 mb-2">
                            <div className="p-2 rounded-lg bg-green-100 dark:bg-green-900/30">
                                <CheckIcon />
                            </div>
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Login Time</h3>
                        </div>
                        <div className="text-3xl font-bold text-gray-900 dark:text-white font-mono">
                            {formatTime(loginTime)}
                        </div>
                    </div>

                    <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 p-6 shadow-sm">
                        <div className="flex items-center gap-3 mb-2">
                            <div className="p-2 rounded-lg bg-orange-100 dark:bg-orange-900/30">
                                <CoffeeIcon />
                            </div>
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Lunch Break</h3>
                        </div>
                        <div className="text-2xl font-bold text-gray-900 dark:text-white font-mono">
                            {lunchStartTime && lunchEndTime 
                                ? `${formatTimeShort(lunchStartTime)} - ${formatTimeShort(lunchEndTime)}`
                                : lunchStartTime 
                                ? `${formatTimeShort(lunchStartTime)} - ...`
                                : '--:-- - --:--'
                            }
                        </div>
                    </div>

                    <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 p-6 shadow-sm">
                        <div className="flex items-center gap-3 mb-2">
                            <div className="p-2 rounded-lg bg-red-100 dark:bg-red-900/30">
                                <ClockIcon />
                            </div>
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Logout Time</h3>
                        </div>
                        <div className="text-3xl font-bold text-gray-900 dark:text-white font-mono">
                            {formatTime(logoutTime)}
                        </div>
                    </div>

                    <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 p-6 shadow-sm">
                        <div className="flex items-center gap-3 mb-2">
                            <div className={`p-2 rounded-lg ${todayAttendance?.is_late ? 'bg-red-100 dark:bg-red-900/30' : 'bg-blue-100 dark:bg-blue-900/30'}`}>
                                <ClockIcon />
                            </div>
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Today's Status</h3>
                        </div>
                        {todayAttendance && todayAttendance.check_in_time ? (
                            <div className="space-y-2">
                                <div className="flex justify-between items-center text-xs">
                                    <span className="text-gray-600 dark:text-gray-400">Expected:</span>
                                    <span className="font-mono font-semibold text-gray-900 dark:text-white">{formatTimeFromString(todayAttendance.expected_check_in) || '--:--'}</span>
                                </div>
                                <div className="flex justify-between items-center text-xs">
                                    <span className="text-gray-600 dark:text-gray-400">Actual:</span>
                                    <span className="font-mono font-semibold text-gray-900 dark:text-white">{formatTimeFromString(todayAttendance.check_in_time) || '--:--'}</span>
                                </div>
                                {todayAttendance.is_late && todayAttendance.minutes_late > 0 ? (
                                    <div className="mt-2 p-2 bg-red-50 dark:bg-red-900/20 rounded-lg">
                                        <p className="text-xs font-semibold text-red-600 dark:text-red-400">
                                            ⚠️ Late by {todayAttendance.minutes_late} min
                                        </p>
                                        {todayAttendance.minutes_late <= 5 && (
                                            <p className="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                                                Within grace period
                                            </p>
                                        )}
                                    </div>
                                ) : todayAttendance.check_in_time ? (
                                    <div className="mt-2 p-2 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                        <p className="text-xs font-semibold text-green-600 dark:text-green-400">
                                            ✓ On Time
                                        </p>
                                    </div>
                                ) : null}
                            </div>
                        ) : (
                            <p className="text-sm text-gray-400 dark:text-gray-500">Not checked in yet</p>
                        )}
                    </div>
                </div>

                {/* Monthly Stats removed per request */}

                {/* SEAMLESS OVERTIME: Automatic OT Indicator */}
                {todayAttendance?.has_approved_overtime && (
                    <div className="mb-8 rounded-2xl border-2 border-blue-500 dark:border-blue-600 bg-gradient-to-r from-blue-50 to-cyan-50 dark:from-blue-900/40 dark:to-cyan-900/40 shadow-lg overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-4">
                                    <div className="p-3 rounded-xl bg-blue-500 dark:bg-blue-600">
                                        <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-lg font-bold text-blue-900 dark:text-blue-100">
                                            🎯 Overtime Approved & Active
                                        </h3>
                                        <p className="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                            Your shift has been automatically extended
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="relative flex h-3 w-3">
                                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                        <span className="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                                    </span>
                                    <span className="text-xs font-semibold text-green-600 dark:text-green-400 uppercase tracking-wide">Integrated</span>
                                </div>
                            </div>
                            
                            <div className="mt-6 grid grid-cols-3 gap-4">
                                <div className="bg-white dark:bg-gray-800/50 rounded-lg p-4">
                                    <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">Regular Shift Ends</p>
                                    <p className="text-xl font-bold text-gray-900 dark:text-white">
                                        {formatTimeFromString(shopHours?.close)}
                                    </p>
                                </div>
                                <div className="bg-white dark:bg-gray-800/50 rounded-lg p-4">
                                    <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">Extended Until</p>
                                    <p className="text-xl font-bold text-blue-600 dark:text-blue-400">
                                        {formatTimeFromString(todayAttendance.adjusted_checkout_time)}
                                    </p>
                                </div>
                                <div className="bg-white dark:bg-gray-800/50 rounded-lg p-4">
                                    <p className="text-xs text-gray-600 dark:text-gray-400 mb-1">Overtime Hours</p>
                                    <p className="text-xl font-bold text-green-600 dark:text-green-400">
                                        {todayAttendance.overtime_hours} hrs
                                    </p>
                                </div>
                            </div>
                            
                            <div className="mt-4 p-3 bg-blue-500/10 dark:bg-blue-500/20 rounded-lg border border-blue-200 dark:border-blue-700">
                                <p className="text-sm text-blue-800 dark:text-blue-200">
                                    <strong>📋 Reason:</strong> {todayAttendance.overtime_reason}
                                </p>
                            </div>
                            
                            <div className="mt-4 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-700">
                                <div className="flex items-start gap-2">
                                    <svg className="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <p className="text-sm font-semibold text-green-800 dark:text-green-200">
                                            Automatic Tracking Enabled
                                        </p>
                                        <p className="text-xs text-green-700 dark:text-green-300 mt-1">
                                            No manual action required. Continue working and clock out when finished. Overtime hours will be automatically calculated.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Attendance Records Table */}
                <div className="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900/50 shadow-sm overflow-hidden">
                    <div className="p-8 border-b border-gray-200 dark:border-gray-800">
                        <h2 className="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                            <div className="p-2 rounded-lg bg-orange-100 dark:bg-orange-900/30">
                                <CalendarIcon />
                            </div>
                            Attendance History
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-gray-50 dark:bg-gray-800/50">
                                <tr>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Date</th>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Clock In</th>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Expected</th>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Clock Out</th>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Total Hours</th>
                                    <th className="px-8 py-4 text-left text-sm font-semibold text-gray-700 dark:text-gray-300">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {attendanceRecords.length === 0 ? (
                                    <tr className="border-t border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                        <td colSpan={6} className="px-8 py-12 text-center">
                                            <div className="flex flex-col items-center gap-3">
                                                <div className="p-4 rounded-full bg-gray-100 dark:bg-gray-800">
                                                    <CalendarIcon />
                                                </div>
                                                <p className="text-gray-600 dark:text-gray-400 font-medium">
                                                    No attendance records yet
                                                </p>
                                                <p className="text-sm text-gray-500 dark:text-gray-500">
                                                    Start by clocking in to create your first record
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                ) : (
                                    attendanceRecords.map((record, index) => (
                                        <tr key={index} className="border-t border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td className="px-8 py-4 text-gray-900 dark:text-white font-medium">{record.date}</td>
                                            <td className="px-8 py-4">
                                                <div className="flex items-center gap-2">
                                                    <span className={`font-mono ${record.isLate ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'}`}>
                                                        {record.clockIn}
                                                    </span>
                                                    {record.isLate && record.minutesLate && record.minutesLate > 0 && (
                                                        <span className="text-xs bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 px-2 py-1 rounded-full font-semibold">
                                                            +{record.minutesLate}m
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-8 py-4 text-sm text-gray-500 dark:text-gray-400 font-mono">
                                                {record.expectedCheckIn || '--:--'}
                                            </td>
                                            <td className="px-8 py-4 text-gray-900 dark:text-white font-mono">
                                                <div className="flex items-center gap-2">
                                                    {record.clockOut}
                                                    {record.autoClockedOut && (
                                                        <span className="text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-1 rounded-full font-semibold" title={record.autoClockoutReason || 'Auto clocked out'}>
                                                            AUTO
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-8 py-4 text-gray-900 dark:text-white font-mono">{record.totalHours}</td>
                                            <td className="px-8 py-4">
                                                <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ${
                                                    record.status === 'Completed' 
                                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' 
                                                        : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'
                                                }`}>
                                                    {record.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
                </>
                ) : null}

                {/* Leave Request Modal */}
                {showLeaveModal && (
                    <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl max-w-md w-full p-8 border border-gray-200 dark:border-gray-800">
                            <div className="flex items-center justify-between mb-6">
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-3">
                                    <div className="p-2 rounded-lg bg-purple-100 dark:bg-purple-900/30">
                                        <LeaveIcon />
                                    </div>
                                    Request Leave
                                </h2>
                                <button
                                    onClick={() => setShowLeaveModal(false)}
                                    className="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors"
                                >
                                    <svg className="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div className="space-y-6">
                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Month
                                    </label>
                                    <select
                                        value={leaveMonth}
                                        onChange={(e) => setLeaveMonth(e.target.value)}
                                        className="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                    >
                                        <option value="">Select Month</option>
                                        <option value="January">January</option>
                                        <option value="February">February</option>
                                        <option value="March">March</option>
                                        <option value="April">April</option>
                                        <option value="May">May</option>
                                        <option value="June">June</option>
                                        <option value="July">July</option>
                                        <option value="August">August</option>
                                        <option value="September">September</option>
                                        <option value="October">October</option>
                                        <option value="November">November</option>
                                        <option value="December">December</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Day
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="31"
                                        value={leaveDay}
                                        onChange={(e) => setLeaveDay(e.target.value)}
                                        placeholder="Enter day (1-31)"
                                        className="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Reason
                                    </label>
                                    <textarea
                                        value={leaveReason}
                                        onChange={(e) => setLeaveReason(e.target.value)}
                                        placeholder="Enter reason for leave..."
                                        rows={4}
                                        className="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all resize-none"
                                    />
                                </div>

                                <div className="flex gap-3 pt-4">
                                    <button
                                        onClick={() => setShowLeaveModal(false)}
                                        className="flex-1 px-6 py-3 rounded-xl font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-300"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={handleLeaveRequest}
                                        className="flex-1 px-6 py-3 rounded-xl font-semibold text-white bg-blue-500 hover:bg-purple-600 transition-all duration-300 shadow-lg hover:shadow-xl"
                                    >
                                        Submit Request
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Overtime Modal */}
                {showOvertimeModal && (
                    <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-md w-full p-8">
                            <div className="mb-6">
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Request Overtime</h2>
                                <p className="text-gray-600 dark:text-gray-400 mt-1 text-sm">Submit your overtime request for approval</p>
                            </div>

                            <div className="space-y-6">
                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                                        Hours of Overtime <span className="text-red-500">*</span>
                                    </label>
                                    <div className="grid grid-cols-4 gap-3">
                                        {[1, 2, 3, 4].map((hours) => (
                                            <button
                                                key={hours}
                                                onClick={() => setOvertimeHours(hours.toString())}
                                                className={`py-3 px-2 rounded-lg font-semibold transition-all duration-200 ${
                                                    overtimeHours === hours.toString()
                                                        ? 'bg-blue-600 text-white shadow-lg'
                                                        : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
                                                }`}
                                            >
                                                {hours}h
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        Reason for Overtime <span className="text-red-500">*</span>
                                    </label>
                                    <select
                                        value={overtimeReason}
                                        onChange={(e) => {
                                            setOvertimeReason(e.target.value);
                                            if (e.target.value !== 'Others') {
                                                setOvertimeCustomReason('');
                                            }
                                        }}
                                        className="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    >
                                        <option value="">Select a reason</option>
                                        <option value="Work backlog">Work backlog</option>
                                        <option value="Urgent deadline">Urgent deadline</option>
                                        <option value="Client requirement">Client requirement</option>
                                        <option value="System issue">System issue</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>

                                {overtimeReason === 'Others' && (
                                    <div>
                                        <label className="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                            Please specify reason <span className="text-red-500">*</span>
                                        </label>
                                        <textarea
                                            value={overtimeCustomReason}
                                            onChange={(e) => setOvertimeCustomReason(e.target.value)}
                                            placeholder="Enter your reason for overtime..."
                                            rows={3}
                                            className="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all resize-none"
                                        />
                                    </div>
                                )}

                                <div className="flex gap-3 pt-4">
                                    <button
                                        onClick={() => setShowOvertimeModal(false)}
                                        disabled={isLoading}
                                        className="flex-1 px-6 py-3 rounded-xl font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        onClick={handleOvertimeRequest}
                                        disabled={isLoading || !overtimeHours || !overtimeReason || (overtimeReason === 'Others' && !overtimeCustomReason)}
                                        className="flex-1 px-6 py-3 rounded-xl font-semibold text-white bg-blue-600 hover:bg-blue-700 transition-all duration-300 shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none flex items-center justify-center gap-2"
                                    >
                                        {isLoading ? (
                                            <>
                                                <svg className="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Submitting...
                                            </>
                                        ) : (
                                            'Submit Request'
                                        )}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayoutERP>
    );
}
