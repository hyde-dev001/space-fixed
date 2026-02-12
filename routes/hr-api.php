<?php

/**
 * HR Module API Routes
 * 
 * Purpose: Human Resources management endpoints
 * Middleware: web, auth:user (session-based), role-based access control
 * Protected by: HR, shop_owner roles + shop isolation
 * 
 * Endpoints:
 * - Employee management
 * - Attendance tracking
 * - Leave requests
 * - Payroll processing
 * - Performance reviews
 * - Departments
 * - Employee documents
 * - Audit logs (HR module)
 * - Notifications
 * - Training management
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ERP\HR\EmployeeController;
use App\Http\Controllers\ERP\HR\AttendanceController;
use App\Http\Controllers\ERP\HR\LeaveController;
use App\Http\Controllers\ERP\HR\PayrollController;
use App\Http\Controllers\ERP\HR\PerformanceController;
use App\Http\Controllers\ERP\HR\DepartmentController;
use App\Http\Controllers\ERP\HR\DocumentController;
use App\Http\Controllers\ERP\HR\AuditLogController as HRAuditLogController;
use App\Http\Controllers\ERP\HR\NotificationController;
use App\Http\Controllers\ERP\HR\TrainingController;
use App\Http\Controllers\ERP\HR\HRAnalyticsController;
use App\Http\Controllers\ERP\HR\SuspensionRequestController;

/**
 * HR Module Routes
 * All routes require authentication and permission-based access
 * Users must have at least one HR-related permission (view-employees, view-attendance, view-payroll)
 */
Route::prefix('api/hr')->middleware(['auth:user', 'permission:view-employees|view-attendance|view-payroll', 'shop.isolation'])->group(function () {
    // ============================================
    // DASHBOARD & ANALYTICS
    // ============================================
    Route::get('/dashboard', [HRAnalyticsController::class, 'dashboard'])->name('hr.dashboard');

    // ============================================
    // EMPLOYEE MANAGEMENT
    // ============================================
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index'])->name('hr.employees.index');
        Route::post('/', [EmployeeController::class, 'store'])->name('hr.employees.store');
        Route::get('/statistics', [EmployeeController::class, 'statistics'])->name('hr.employees.statistics');
        Route::get('/{id}', [EmployeeController::class, 'show'])->name('hr.employees.show');
        Route::put('/{id}', [EmployeeController::class, 'update'])->name('hr.employees.update');
        Route::delete('/{id}', [EmployeeController::class, 'destroy'])->name('hr.employees.destroy');
        Route::post('/{id}/suspend', [EmployeeController::class, 'suspend'])->name('hr.employees.suspend');
        Route::post('/{id}/activate', [EmployeeController::class, 'activate'])->name('hr.employees.activate');
        Route::post('/{userId}/roles/sync', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'syncAdditionalRoles'])->name('hr.employees.roles.sync');
    });

    // ============================================
    // SUSPENSION REQUESTS (HR → Manager → Owner)
    // ============================================
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [SuspensionRequestController::class, 'index'])->name('hr.suspension_requests.index');
        Route::post('/', [SuspensionRequestController::class, 'store'])->name('hr.suspension_requests.store');
        Route::get('/{id}', [SuspensionRequestController::class, 'show'])->name('hr.suspension_requests.show');
    });

    // ============================================
    // ATTENDANCE TRACKING
    // ============================================
    Route::prefix('attendance')->group(function () {
        Route::get('/', [AttendanceController::class, 'index'])->name('hr.attendance.index');
        Route::post('/', [AttendanceController::class, 'store'])->name('hr.attendance.store');
        Route::get('/statistics', [AttendanceController::class, 'statistics'])->name('hr.attendance.statistics');
        Route::post('/check-in', [AttendanceController::class, 'checkIn'])->name('hr.attendance.checkin');
        Route::post('/check-out', [AttendanceController::class, 'checkOut'])->name('hr.attendance.checkout');
        Route::get('/employee/{employeeId}', [AttendanceController::class, 'getByEmployee'])->name('hr.attendance.by_employee');
        
        // Lateness tracking routes
        Route::get('/lateness/stats', [AttendanceController::class, 'getLatenessStats'])->name('hr.attendance.lateness.stats');
        Route::get('/lateness/top-late', [AttendanceController::class, 'getMostLateEmployees'])->name('hr.attendance.lateness.top');
        Route::get('/lateness/employee/{employeeId}', [AttendanceController::class, 'getEmployeeLatenessReport'])->name('hr.attendance.lateness.employee');
        Route::get('/lateness/daily/{date}', [AttendanceController::class, 'getDailyLatenessSummary'])->name('hr.attendance.lateness.daily');
        Route::get('/lateness/trends', [AttendanceController::class, 'getLatenessTrends'])->name('hr.attendance.lateness.trends');
        
        Route::get('/{id}', [AttendanceController::class, 'show'])->name('hr.attendance.show');
        Route::put('/{id}', [AttendanceController::class, 'update'])->name('hr.attendance.update');
        Route::delete('/{id}', [AttendanceController::class, 'destroy'])->name('hr.attendance.destroy');
    });

    // ============================================
    // LEAVE MANAGEMENT
    // ============================================
    Route::prefix('leave-requests')->group(function () {
        Route::get('/', [LeaveController::class, 'index'])->name('hr.leave.index');
        Route::post('/', [LeaveController::class, 'store'])->name('hr.leave.store');
        Route::get('/pending', [LeaveController::class, 'getPending'])->name('hr.leave.pending');
        Route::get('/employee/{employeeId}/balance', [LeaveController::class, 'getBalance'])->name('hr.leave.balance');
        Route::get('/{id}', [LeaveController::class, 'show'])->name('hr.leave.show');
        Route::put('/{id}', [LeaveController::class, 'update'])->name('hr.leave.update');
        Route::delete('/{id}', [LeaveController::class, 'destroy'])->name('hr.leave.destroy');
        Route::post('/{id}/approve', [LeaveController::class, 'approve'])->name('hr.leave.approve');
        Route::post('/{id}/reject', [LeaveController::class, 'reject'])->name('hr.leave.reject');
    });

    // ============================================
    // OVERTIME MANAGEMENT
    // ============================================
    Route::prefix('overtime-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'index'])->name('hr.overtime.index');
        Route::post('/{id}/approve', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'approve'])->name('hr.overtime.approve');
        Route::post('/{id}/reject', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'reject'])->name('hr.overtime.reject');
        Route::post('/{id}/confirm-hours', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'confirmHours'])->name('hr.overtime.confirm_hours');
        Route::post('/assign', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'assignOvertime'])->name('hr.overtime.assign');
    });

    // ============================================
    // PAYROLL PROCESSING
    // ============================================
    Route::prefix('payroll')->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->name('hr.payroll.index');
        Route::post('/', [PayrollController::class, 'store'])->name('hr.payroll.store');
        Route::post('/calculate-preview', [PayrollController::class, 'calculatePreview'])->name('hr.payroll.calculate_preview');
        Route::post('/generate', [PayrollController::class, 'generatePayroll'])->name('hr.payroll.generate');
        Route::post('/process', [PayrollController::class, 'processPayroll'])->name('hr.payroll.process');
        
        // Batch operations
        Route::post('/batch/preview', [PayrollController::class, 'previewBatch'])->name('hr.payroll.batch.preview');
        Route::post('/batch/generate', [PayrollController::class, 'generateBatch'])->name('hr.payroll.batch.generate');
        Route::post('/batch/retry', [PayrollController::class, 'retryBatch'])->name('hr.payroll.batch.retry');
        Route::post('/batch/export', [PayrollController::class, 'exportBatch'])->name('hr.payroll.batch.export');
        
        Route::get('/employee/{employeeId}', [PayrollController::class, 'getByEmployee'])->name('hr.payroll.by_employee');
        Route::get('/{id}', [PayrollController::class, 'show'])->name('hr.payroll.show');
        Route::put('/{id}', [PayrollController::class, 'update'])->name('hr.payroll.update');
        Route::delete('/{id}', [PayrollController::class, 'destroy'])->name('hr.payroll.destroy');
        Route::get('/{id}/export', [PayrollController::class, 'exportPayslip'])->name('hr.payroll.export');
    });

    // ============================================
    // PAYSLIP APPROVALS (Finance approval before HR release)
    // ============================================
    Route::prefix('payslip-approvals')->middleware(['permission:approve-payroll'])->group(function () {
        Route::get('/', [PayrollController::class, 'getPayslipsForApproval'])->name('hr.payslip_approval.index');
        Route::get('/{id}', [PayrollController::class, 'getPayslipForApproval'])->name('hr.payslip_approval.show');
        Route::post('/{id}/approve', [PayrollController::class, 'approvePayslip'])->name('hr.payslip_approval.approve');
        Route::post('/{id}/reject', [PayrollController::class, 'rejectPayslip'])->name('hr.payslip_approval.reject');
        Route::post('/batch/preview', [PayrollController::class, 'batchApprovalPreview'])->name('hr.payslip_approval.batch_preview');
        Route::post('/batch/approve', [PayrollController::class, 'batchApprove'])->name('hr.payslip_approval.batch_approve');
    });

    // ============================================
    // PERFORMANCE REVIEWS
    // ============================================
    Route::prefix('performance-reviews')->group(function () {
        Route::get('/', [PerformanceController::class, 'index'])->name('hr.performance.index');
        Route::post('/', [PerformanceController::class, 'store'])->name('hr.performance.store');
        Route::get('/employee/{employeeId}', [PerformanceController::class, 'getByEmployee'])->name('hr.performance.by_employee');
        Route::get('/{id}', [PerformanceController::class, 'show'])->name('hr.performance.show');
        Route::put('/{id}', [PerformanceController::class, 'update'])->name('hr.performance.update');
        Route::delete('/{id}', [PerformanceController::class, 'destroy'])->name('hr.performance.destroy');
        Route::post('/{id}/submit', [PerformanceController::class, 'submit'])->name('hr.performance.submit');
    });

    // ============================================
    // DEPARTMENTS
    // ============================================
    Route::prefix('departments')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('hr.departments.index');
        Route::post('/', [DepartmentController::class, 'store'])->name('hr.departments.store');
        Route::get('/statistics', [DepartmentController::class, 'statistics'])->name('hr.departments.statistics');
        Route::get('/{id}', [DepartmentController::class, 'show'])->name('hr.departments.show');
        Route::put('/{id}', [DepartmentController::class, 'update'])->name('hr.departments.update');
        Route::delete('/{id}', [DepartmentController::class, 'destroy'])->name('hr.departments.destroy');
    });

    // ============================================
    // EMPLOYEE DOCUMENTS
    // ============================================
    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('hr.documents.index');
        Route::post('/', [DocumentController::class, 'store'])->name('hr.documents.store');
        Route::get('/reports/expiring', [DocumentController::class, 'expiringDocuments'])->name('hr.documents.expiring');
        Route::get('/reports/expired', [DocumentController::class, 'expiredDocuments'])->name('hr.documents.expired');
        Route::get('/reports/statistics', [DocumentController::class, 'statistics'])->name('hr.documents.statistics');
        Route::get('/metadata/types', [DocumentController::class, 'documentTypes'])->name('hr.documents.types');
        Route::get('/employee/{employeeId}', [DocumentController::class, 'employeeDocuments'])->name('hr.documents.by_employee');
        Route::get('/{id}', [DocumentController::class, 'show'])->name('hr.documents.show');
        Route::put('/{id}', [DocumentController::class, 'update'])->name('hr.documents.update');
        Route::delete('/{id}', [DocumentController::class, 'destroy'])->name('hr.documents.destroy');
        Route::get('/{id}/download', [DocumentController::class, 'download'])->name('hr.documents.download');
        Route::post('/{id}/verify', [DocumentController::class, 'verify'])->name('hr.documents.verify');
        Route::post('/{id}/reject', [DocumentController::class, 'reject'])->name('hr.documents.reject');
    });

    // ============================================
    // AUDIT LOGS (HR Module)
    // ============================================
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [HRAuditLogController::class, 'index'])->name('hr.audit.index');
        Route::get('/statistics', [HRAuditLogController::class, 'statistics'])->name('hr.audit.statistics');
        Route::get('/entity/history', [HRAuditLogController::class, 'entityHistory'])->name('hr.audit.entity_history');
        Route::get('/critical', [HRAuditLogController::class, 'criticalLogs'])->name('hr.audit.critical');
        Route::get('/export', [HRAuditLogController::class, 'export'])->name('hr.audit.export');
        Route::get('/filters/options', [HRAuditLogController::class, 'filterOptions'])->name('hr.audit.filter_options');
        Route::get('/user/{userId}/activity', [HRAuditLogController::class, 'userActivity'])->name('hr.audit.user_activity');
        Route::get('/employee/{employeeId}/activity', [HRAuditLogController::class, 'employeeActivity'])->name('hr.audit.employee_activity');
        Route::get('/{id}', [HRAuditLogController::class, 'show'])->name('hr.audit.show');
    });

    // ============================================
    // NOTIFICATIONS
    // ============================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('hr.notifications.index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('hr.notifications.unread_count');
        Route::get('/stats', [NotificationController::class, 'stats'])->name('hr.notifications.stats');
        Route::post('/{id}/mark-as-read', [NotificationController::class, 'markAsRead'])->name('hr.notifications.mark_as_read');
        Route::post('/mark-all-as-read', [NotificationController::class, 'markAllAsRead'])->name('hr.notifications.mark_all_as_read');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('hr.notifications.destroy');
        Route::delete('/clear-read', [NotificationController::class, 'clearRead'])->name('hr.notifications.clear_read');
    });

    // ============================================
    // TRAINING MANAGEMENT
    // ============================================
    Route::prefix('training')->group(function () {
        // Training Programs
        Route::get('/programs', [TrainingController::class, 'index'])->name('hr.training.programs.index');
        Route::post('/programs', [TrainingController::class, 'store'])->name('hr.training.programs.store');
        Route::get('/programs/{id}', [TrainingController::class, 'show'])->name('hr.training.programs.show');
        Route::put('/programs/{id}', [TrainingController::class, 'update'])->name('hr.training.programs.update');
        Route::delete('/programs/{id}', [TrainingController::class, 'destroy'])->name('hr.training.programs.destroy');

        // Training Sessions
        Route::get('/sessions', [TrainingController::class, 'sessions'])->name('hr.training.sessions.index');
        Route::post('/sessions', [TrainingController::class, 'storeSession'])->name('hr.training.sessions.store');
        Route::put('/sessions/{id}', [TrainingController::class, 'updateSession'])->name('hr.training.sessions.update');
        Route::delete('/sessions/{id}', [TrainingController::class, 'destroySession'])->name('hr.training.sessions.destroy');

        // Enrollments
        Route::get('/enrollments', [TrainingController::class, 'enrollments'])->name('hr.training.enrollments.index');
        Route::post('/enroll', [TrainingController::class, 'enroll'])->name('hr.training.enroll');
        Route::put('/enrollments/{id}', [TrainingController::class, 'updateEnrollment'])->name('hr.training.enrollments.update');
        Route::post('/enrollments/{id}/complete', [TrainingController::class, 'completeEnrollment'])->name('hr.training.enrollments.complete');

        // Certifications
        Route::get('/certifications', [TrainingController::class, 'certifications'])->name('hr.training.certifications.index');

        // Statistics
        Route::get('/statistics', [TrainingController::class, 'statistics'])->name('hr.training.statistics');
    });

    // ============================================
    // PERMISSION MANAGEMENT
    // ============================================
    Route::get('/permissions/available', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'getAvailablePermissions'])->name('hr.permissions.available');
    Route::get('/employees/{userId}/permissions', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'getEmployeePermissions'])->name('hr.employees.permissions.get');
    Route::post('/employees/{userId}/permissions', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'updateEmployeePermissions'])->name('hr.employees.permissions.update');
    Route::post('/employees/{userId}/permissions/sync', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'syncEmployeePermissions'])->name('hr.employees.permissions.sync');

    // ============================================
    // POSITION TEMPLATES
    // ============================================
    Route::get('/position-templates', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'getPositionTemplates'])->name('hr.position-templates.index');
    Route::post('/employees/{userId}/apply-template', [\App\Http\Controllers\ShopOwner\UserAccessControlController::class, 'applyPositionTemplate'])->name('hr.employees.apply-template');
});

/**
 * Staff/Manager Self-Service Routes
 * Protected by: STAFF, MANAGER, shop_owner roles
 */
Route::prefix('api/staff')->middleware(['auth:user', 'old_role:Staff|Manager|Shop Owner'])->group(function () {
    // ============================================
    // SELF-SERVICE ATTENDANCE
    // ============================================
    Route::prefix('attendance')->group(function () {
        Route::post('/check-in', [AttendanceController::class, 'selfCheckIn'])->name('staff.attendance.checkin');
        Route::post('/check-out', [AttendanceController::class, 'selfCheckOut'])->name('staff.attendance.checkout');
        Route::get('/my-records', [AttendanceController::class, 'myRecords'])->name('staff.attendance.my_records');
        Route::get('/status', [AttendanceController::class, 'checkStatus'])->name('staff.attendance.status');
        Route::get('/my-lateness-stats', [AttendanceController::class, 'getMyLatenessStats'])->name('staff.attendance.my_lateness_stats');
        Route::patch('/{id}/add-lateness-reason', [AttendanceController::class, 'addLatenessReason'])->name('staff.attendance.add_lateness_reason');
        Route::patch('/{id}/add-early-reason', [AttendanceController::class, 'addEarlyReason'])->name('staff.attendance.add_early_reason');
    });

    // ============================================
    // SHOP HOURS
    // ============================================
    Route::get('/shop-hours/today', [AttendanceController::class, 'getShopHoursToday'])->name('staff.shop_hours.today');

    // ============================================
    // SELF-SERVICE LEAVE
    // ============================================
    Route::prefix('leave')->group(function () {
        Route::post('/request', [LeaveController::class, 'selfRequestLeave'])->name('staff.leave.request');
    });

    // ============================================
    // SELF-SERVICE OVERTIME
    // ============================================
    Route::prefix('overtime')->group(function () {
        Route::post('/request', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'staffRequest'])->name('staff.overtime.request');
        Route::get('/my-requests', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'staffMyRequests'])->name('staff.overtime.my_requests');
        Route::get('/today-approved', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'getTodayApprovedOvertime'])->name('staff.overtime.today_approved');
        Route::post('/{id}/check-in', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'overtimeCheckIn'])->name('staff.overtime.check_in');
        Route::post('/{id}/check-out', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'overtimeCheckOut'])->name('staff.overtime.check_out');
        Route::post('/{id}/cancel', [\App\Http\Controllers\ERP\HR\OvertimeController::class, 'cancel'])->name('staff.overtime.cancel');
    });
});
