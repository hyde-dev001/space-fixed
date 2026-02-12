<?php

namespace App\Http\Controllers\ERP\HR;

use App\Http\Controllers\Controller;
use App\Models\HR\Payroll;
use App\Models\HR\PayrollComponent;
use App\Models\Employee;
use App\Models\HR\AttendanceRecord;
use App\Models\HR\AuditLog;
use App\Services\HR\PayrollService;
use App\Traits\HR\LogsHRActivity;
use App\Notifications\HR\PayslipGenerated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PayrollController extends Controller
{
    use LogsHRActivity;
    
    protected PayrollService $payrollService;
    
    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }
    /**
     * Display a listing of payrolls.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee:id,first_name,last_name,department');

        // Apply search filter
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('payroll_period', 'like', "%{$searchTerm}%")
                  ->orWhere('employee_id', 'like', "%{$searchTerm}%")
                  ->orWhereHas('employee', function ($empQuery) use ($searchTerm) {
                      $empQuery->where('first_name', 'like', "%{$searchTerm}%")
                               ->orWhere('last_name', 'like', "%{$searchTerm}%")
                               ->orWhere('department', 'like', "%{$searchTerm}%")
                               ->orWhere('employee_id', 'like', "%{$searchTerm}%");
                  });
            });
        }

        // Apply filters
        if ($request->filled('employee_id')) {
            $query->forEmployee($request->employee_id);
        }

        if ($request->filled('period')) {
            $query->forPeriod($request->period);
        }

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('department')) {
            $query->whereHas('employee', function ($q) use ($request) {
                $q->where('department', $request->department);
            });
        }

        $payrolls = $query->orderBy('payroll_period', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($payrolls);
    }

    /**
     * Store a newly created payroll.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'payrollPeriod' => 'required|string', // e.g., "January 2026"
            'baseSalary' => 'required|numeric|min:0',
            'salesCommission' => 'nullable|numeric|min:0',
            'performanceBonus' => 'nullable|numeric|min:0',
            'otherAllowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'paymentMethod' => 'required|in:bank_transfer,check,cash',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        // Check if payroll already exists for this employee and period
        $existingPayroll = Payroll::forEmployee($request->employee_id)
            ->forPeriod($request->payrollPeriod)
            ->first();

        if ($existingPayroll) {
            return response()->json([
                'error' => 'Payroll already exists for this employee and period'
            ], 422);
        }

        // Map camelCase request fields to snake_case database columns
        $salesCommission = $request->salesCommission ?? 0;
        $performanceBonus = $request->performanceBonus ?? 0;
        $otherAllowances = $request->otherAllowances ?? 0;
        $totalAllowances = $salesCommission + $performanceBonus + $otherAllowances;
        
        $payrollData = [
            'employee_id' => $request->employee_id,
            'shop_owner_id' => $user->shop_owner_id,
            'payroll_period' => $request->payrollPeriod,
            'base_salary' => $request->baseSalary,
            'allowances' => $totalAllowances,
            'deductions' => $request->deductions ?? 0,
            'payment_method' => $request->paymentMethod,
            'status' => 'pending',
            'generated_by' => $user->id,
            'generated_at' => now(),
        ];
        
        // Calculate gross and net salary
        $payrollData['gross_salary'] = $payrollData['base_salary'] + $payrollData['allowances'];
        $payrollData['net_salary'] = $payrollData['gross_salary'] - $payrollData['deductions'];

        $payroll = Payroll::create($payrollData);

        // Create detailed components for transparency
        if ($salesCommission > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Sales Commission',
                'calculated_amount' => $salesCommission,
            ]);
        }
        
        if ($performanceBonus > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Performance Bonus',
                'calculated_amount' => $performanceBonus,
            ]);
        }
        
        if ($otherAllowances > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Other Allowances',
                'calculated_amount' => $otherAllowances,
            ]);
        }
        
        if ($request->deductions > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_DEDUCTION,
                'component_name' => 'Total Deductions',
                'calculated_amount' => $request->deductions,
            ]);
        }

        // Audit log
        $this->auditCustom(
            AuditLog::MODULE_PAYROLL,
            AuditLog::ACTION_GENERATED,
            "Payroll generated: {$employee->first_name} {$employee->last_name} - Period {$request->payrollPeriod} - Net: {$payrollData['net_salary']}",
            [
                'severity' => AuditLog::SEVERITY_WARNING,
                'tags' => ['financial', 'payroll', 'sensitive'],
                'employee_id' => $employee->id,
                'entity_type' => Payroll::class,
                'entity_id' => $payroll->id,
            ]
        );

        return response()->json([
            'message' => 'Payroll created successfully',
            'payroll' => $payroll->load('employee')
        ], 201);
    }

    /**
     * Display the specified payroll.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        return response()->json($payroll);
    }

    /**
     * Update the specified payroll.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        // Only allow updates if status is pending
        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot update payroll that is not pending'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'baseSalary' => 'sometimes|required|numeric|min:0',
            'allowances' => 'sometimes|nullable|numeric|min:0',
            'deductions' => 'sometimes|nullable|numeric|min:0',
            'paymentMethod' => 'sometimes|required|in:bank-transfer,check,cash',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Recalculate net salary if any amounts changed
        if (isset($data['baseSalary']) || isset($data['allowances']) || isset($data['deductions'])) {
            $baseSalary = $data['baseSalary'] ?? $payroll->baseSalary;
            $allowances = $data['allowances'] ?? $payroll->allowances;
            $deductions = $data['deductions'] ?? $payroll->deductions;
            $data['netSalary'] = $baseSalary + $allowances - $deductions;
        }

        $payroll->update($data);

        return response()->json([
            'message' => 'Payroll updated successfully',
            'payroll' => $payroll->load('employee')
        ]);
    }

    /**
     * Remove the specified payroll.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        // Only allow deletion if status is pending
        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot delete payroll that is not pending'
            ], 422);
        }

        $payroll->delete();

        return response()->json(['message' => 'Payroll deleted successfully']);
    }

    /**
     * Generate payroll for multiple employees using PayrollService.
     * 
     * Security: Requires PAYROLL_MANAGER role or shop_owner
     */
    public function generate(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized payroll generation attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first()
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can generate payroll.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds' => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
            'paymentMethod' => 'sometimes|in:bank_transfer,check,cash',
            'overrides' => 'sometimes|array',
            'overrides.*.employee_id' => 'required|exists:employees,id',
            'overrides.*.attendance_days' => 'sometimes|integer|min:0|max:31',
            'overrides.*.leave_days' => 'sometimes|integer|min:0|max:31',
            'overrides.*.overtime_hours' => 'sometimes|numeric|min:0|max:744',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrollPeriod = $request->payrollPeriod;
        $employeeIds = $request->employeeIds;
        $paymentMethod = $request->get('paymentMethod', 'bank_transfer');
        $overridesData = $request->get('overrides', []);

        // Build overrides map by employee_id
        $overridesMap = [];
        foreach ($overridesData as $override) {
            $overridesMap[$override['employee_id']] = $override;
        }

        $createdPayrolls = [];
        $errors = [];

        foreach ($employeeIds as $employeeId) {
            try {
                // Check if employee belongs to the same shop owner
                $employee = Employee::forShopOwner($user->shop_owner_id)
                    ->where('status', 'active')
                    ->findOrFail($employeeId);

                // Check if payroll already exists
                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    $errors[] = "Payroll already exists for {$employee->first_name} {$employee->last_name}";
                    continue;
                }

                // Get employee-specific overrides
                $employeeOverrides = $overridesMap[$employeeId] ?? [];
                $employeeOverrides['payment_method'] = $paymentMethod;

                // Generate payroll using service
                $payroll = $this->payrollService->generatePayroll(
                    $employee,
                    $payrollPeriod,
                    [], // custom components (empty for now)
                    $employeeOverrides
                );

                $createdPayrolls[] = $payroll;

                \Log::info('Payroll generated via service', [
                    'generator_id' => $user->id,
                    'generator_role' => $user->getRoleNames()->first(),
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employeeId,
                    'period' => $payrollPeriod,
                    'net_salary' => $payroll->net_salary,
                    'components_count' => $payroll->components->count()
                ]);

            } catch (\Exception $e) {
                $errors[] = "Error creating payroll for employee ID {$employeeId}: " . $e->getMessage();
                \Log::error('Payroll generation error', [
                    'employee_id' => $employeeId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return response()->json([
            'message' => 'Payroll generation completed',
            'created' => count($createdPayrolls),
            'errors' => $errors,
            'payrolls' => $createdPayrolls
        ]);
    }

    /**
     * Approve payroll (separate from generation for dual control).
     * 
     * Security: Requires PAYROLL_APPROVER role or shop_owner, prevents self-approval
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized payroll approval attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'payroll_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. Only Managers or users with HR permissions can approve payroll.'
            ], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        // Security Check: Prevent self-approval
        if ($payroll->generated_by == $user->id) {
            \Log::warning('Attempted self-approval of payroll', [
                'user_id' => $user->id,
                'payroll_id' => $id
            ]);
            return response()->json([
                'error' => 'You cannot approve payroll that you generated. Requires independent approval.'
            ], 403);
        }

        // Business Logic: Check status
        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Payroll is not pending approval'
            ], 422);
        }

        $payroll->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now()
        ]);

        // Audit logging
        \Log::info('Payroll approved', [
            'approver_id' => $user->id,
            'approver_role' => $user->getRoleNames()->first(),
            'payroll_id' => $id,
            'employee_id' => $payroll->employee_id,
            'amount' => $payroll->netSalary
        ]);

        // Send payslip notification to employee
        try {
            $employee = $payroll->employee;
            if ($employee && $employee->user) {
                $employee->user->notify(new PayslipGenerated($payroll));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send payslip notification', [
                'payroll_id' => $payroll->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Payroll approved successfully',
            'payroll' => $payroll
        ]);
    }

    /**
     * Preview batch payroll generation with validation summary.
     * Returns calculation preview without saving to database.
     */
    public function previewBatch(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds' => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrollPeriod = $request->payrollPeriod;
        $employeeIds = $request->employeeIds;
        
        $previews = [];
        $errors = [];
        $warnings = [];

        foreach ($employeeIds as $employeeId) {
            try {
                $employee = Employee::forShopOwner($user->shop_owner_id)
                    ->where('status', 'active')
                    ->findOrFail($employeeId);

                // Check if payroll already exists
                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    $warnings[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'message' => 'Payroll already exists for this period',
                        'severity' => 'warning'
                    ];
                    continue;
                }

                // Fetch actual attendance data
                [$periodStart, $periodEnd] = $this->parsePeriod($payrollPeriod);
                $attendanceData = $this->getAttendanceData($employeeId, $periodStart, $periodEnd);
                
                // Validate attendance is finalized
                if (!$attendanceData['is_finalized']) {
                    $warnings[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'message' => 'Attendance not finalized for this period',
                        'severity' => 'error'
                    ];
                    continue;
                }

                // Calculate preview (without saving)
                $calculation = $this->calculatePayrollPreview($employee, $attendanceData, $periodStart, $periodEnd);
                
                $previews[] = [
                    'employee_id' => $employeeId,
                    'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                    'department' => $employee->department,
                    'position' => $employee->position,
                    'attendance' => $attendanceData,
                    'calculation' => $calculation,
                ];

            } catch (\Exception $e) {
                $errors[] = [
                    'employee_id' => $employeeId,
                    'message' => $e->getMessage(),
                    'severity' => 'error'
                ];
            }
        }

        return response()->json([
            'previews' => $previews,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total_employees' => count($employeeIds),
                'preview_count' => count($previews),
                'error_count' => count($errors),
                'warning_count' => count($warnings),
                'total_gross' => array_sum(array_column(array_column($previews, 'calculation'), 'gross_salary')),
                'total_net' => array_sum(array_column(array_column($previews, 'calculation'), 'net_salary')),
            ]
        ]);
    }

    /**
     * Generate batch payroll with comprehensive error handling and retry logic.
     * Moves all calculation logic to backend.
     */
    public function generateBatch(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized batch payroll generation attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first()
            ]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds' => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
            'paymentMethod' => 'sometimes|in:bank_transfer,check,cash',
            'sendNotifications' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrollPeriod = $request->payrollPeriod;
        $employeeIds = $request->employeeIds;
        $paymentMethod = $request->get('paymentMethod', 'bank_transfer');
        $sendNotifications = $request->get('sendNotifications', true);
        
        $createdPayrolls = [];
        $errors = [];
        $retryQueue = [];

        // Audit log for batch operation start
        $this->auditCustom(
            AuditLog::MODULE_PAYROLL,
            'batch_generate_started',
            "Batch payroll generation started for period {$payrollPeriod} - {$user->name} ({$user->id})",
            [
                'severity' => AuditLog::SEVERITY_INFO,
                'tags' => ['batch', 'payroll', 'generation'],
                'employee_count' => count($employeeIds),
                'period' => $payrollPeriod,
            ]
        );

        foreach ($employeeIds as $employeeId) {
            try {
                $employee = Employee::forShopOwner($user->shop_owner_id)
                    ->where('status', 'active')
                    ->findOrFail($employeeId);

                // Check for duplicates
                $existingPayroll = Payroll::forEmployee($employeeId)
                    ->forPeriod($payrollPeriod)
                    ->first();

                if ($existingPayroll) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'error' => 'Payroll already exists for this period',
                        'error_code' => 'DUPLICATE_PAYROLL'
                    ];
                    continue;
                }

                // Fetch actual attendance data from backend
                [$periodStart, $periodEnd] = $this->parsePeriod($payrollPeriod);
                $attendanceData = $this->getAttendanceData($employeeId, $periodStart, $periodEnd);
                
                // Validate attendance is finalized
                if (!$attendanceData['is_finalized']) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                        'error' => 'Attendance not finalized for this period',
                        'error_code' => 'ATTENDANCE_NOT_FINALIZED'
                    ];
                    continue;
                }

                // Calculate payroll using backend logic
                $calculation = $this->calculatePayrollPreview($employee, $attendanceData, $periodStart, $periodEnd);
                
                // Create payroll record
                $payroll = Payroll::create([
                    'employee_id' => $employee->id,
                    'shop_owner_id' => $user->shop_owner_id,
                    'payroll_period' => $payrollPeriod,
                    'pay_period_start' => $periodStart,
                    'pay_period_end' => $periodEnd,
                    'base_salary' => $calculation['base_salary'],
                    'allowances' => $calculation['total_allowances'],
                    'deductions' => $calculation['total_deductions'],
                    'gross_salary' => $calculation['gross_salary'],
                    'net_salary' => $calculation['net_salary'],
                    'payment_method' => $paymentMethod,
                    'status' => 'pending',
                    'generated_by' => $user->id,
                    'generated_at' => now(),
                ]);

                // Create detailed components
                $this->createPayrollComponents($payroll, $calculation);

                $createdPayrolls[] = $payroll->load('employee');

                // Audit log for individual payroll
                $this->auditCustom(
                    AuditLog::MODULE_PAYROLL,
                    AuditLog::ACTION_GENERATED,
                    "Payroll generated: {$employee->first_name} {$employee->last_name} - Period {$payrollPeriod} - Net: {$calculation['net_salary']}",
                    [
                        'severity' => AuditLog::SEVERITY_WARNING,
                        'tags' => ['financial', 'payroll', 'sensitive'],
                        'employee_id' => $employee->id,
                        'entity_type' => Payroll::class,
                        'entity_id' => $payroll->id,
                    ]
                );

                // Send notification if enabled
                if ($sendNotifications && $employee->user) {
                    try {
                        $employee->user->notify(new PayslipGenerated($payroll));
                    } catch (\Exception $e) {
                        \Log::error('Failed to send payslip notification', [
                            'payroll_id' => $payroll->id,
                            'employee_id' => $employee->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

            } catch (\Exception $e) {
                $errorDetails = [
                    'employee_id' => $employeeId,
                    'employee_name' => isset($employee) ? $employee->first_name . ' ' . $employee->last_name : 'Unknown',
                    'error' => $e->getMessage(),
                    'error_code' => 'GENERATION_FAILED',
                    'trace' => $e->getTraceAsString()
                ];
                
                $errors[] = $errorDetails;
                $retryQueue[] = $employeeId;
                
                \Log::error('Batch payroll generation error', $errorDetails);
            }
        }

        // Audit log for batch operation completion
        $this->auditCustom(
            AuditLog::MODULE_PAYROLL,
            'batch_generate_completed',
            "Batch payroll generation completed for period {$payrollPeriod} - Success: " . count($createdPayrolls) . ", Failed: " . count($errors),
            [
                'severity' => count($errors) > 0 ? AuditLog::SEVERITY_WARNING : AuditLog::SEVERITY_INFO,
                'tags' => ['batch', 'payroll', 'generation'],
                'success_count' => count($createdPayrolls),
                'error_count' => count($errors),
                'period' => $payrollPeriod,
            ]
        );

        return response()->json([
            'message' => 'Batch payroll generation completed',
            'created' => count($createdPayrolls),
            'errors' => count($errors),
            'payrolls' => $createdPayrolls,
            'error_details' => $errors,
            'retry_queue' => $retryQueue,
            'summary' => [
                'total_gross' => !empty($createdPayrolls) ? array_sum(array_column($createdPayrolls, 'gross_salary')) : 0,
                'total_net' => !empty($createdPayrolls) ? array_sum(array_column($createdPayrolls, 'net_salary')) : 0,
            ]
        ]);
    }

    /**
     * Export batch payroll to CSV or PDF.
     */
    public function exportBatch(Request $request): mixed
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->hasRole('Manager') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'format' => 'required|in:csv,pdf',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payrolls = Payroll::forShopOwner($user->shop_owner_id)
            ->forPeriod($request->payrollPeriod)
            ->with(['employee', 'components'])
            ->get();

        if ($payrolls->isEmpty()) {
            return response()->json(['error' => 'No payrolls found for this period'], 404);
        }

        if ($request->input('format') === 'csv') {
            return $this->exportToCSV($payrolls, $request->payrollPeriod);
        } else {
            return $this->exportToPDF($payrolls, $request->payrollPeriod);
        }
    }

    /**
     * Retry failed payroll generation.
     */
    public function retryBatch(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->hasRole('Manager') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollPeriod' => 'required|string',
            'employeeIds' => 'required|array',
            'employeeIds.*' => 'exists:employees,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Reuse the generateBatch method with retry flag
        return $this->generateBatch($request);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Parse period string to start and end dates.
     */
    protected function parsePeriod(string $period): array
    {
        // Handle formats: "January 2026", "2026-01", "2026-01-01 to 2026-01-31"
        if (strpos($period, ' to ') !== false) {
            return explode(' to ', $period);
        }
        
        // Try parsing "Month YYYY" format
        if (preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $period, $matches)) {
            $monthName = $matches[1];
            $year = $matches[2];
            $monthNum = date('m', strtotime($monthName));
            $startDate = "{$year}-{$monthNum}-01";
            $endDate = date('Y-m-t', strtotime($startDate));
            return [$startDate, $endDate];
        }
        
        // Default: assume YYYY-MM format
        $startDate = $period . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        return [$startDate, $endDate];
    }

    /**
     * Get attendance data for employee in period.
     */
    protected function getAttendanceData(int $employeeId, string $startDate, string $endDate): array
    {
        $records = AttendanceRecord::where('employee_id', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $totalRegularHours = 0;
        $totalOvertimeHours = 0;
        $totalUndertimeHours = 0;
        $totalAbsentDays = 0;
        $totalLateDays = 0;
        $totalPresentDays = 0;

        foreach ($records as $record) {
            if ($record->status === 'present') {
                $totalPresentDays++;
                $totalRegularHours += $record->regular_hours ?? 8;
                $totalOvertimeHours += $record->overtime_hours ?? 0;
                $totalUndertimeHours += $record->undertime_hours ?? 0;
                if ($record->is_late) {
                    $totalLateDays++;
                }
            } elseif ($record->status === 'absent') {
                $totalAbsentDays++;
            }
        }

        // Check if attendance is finalized (attendance exists for most working days)
        $workingDays = Carbon::parse($startDate)->diffInWeekdays(Carbon::parse($endDate)) + 1;
        $isFinalized = $records->count() >= ($workingDays * 0.8); // 80% threshold

        return [
            'total_regular_hours' => $totalRegularHours,
            'total_overtime_hours' => $totalOvertimeHours,
            'total_undertime_hours' => $totalUndertimeHours,
            'total_absent_days' => $totalAbsentDays,
            'total_late_days' => $totalLateDays,
            'total_present_days' => $totalPresentDays,
            'working_days' => $workingDays,
            'is_finalized' => $isFinalized,
        ];
    }

    /**
     * Calculate payroll preview (all calculation logic in backend).
     */
    protected function calculatePayrollPreview(Employee $employee, array $attendanceData, string $startDate, string $endDate): array
    {
        $baseSalary = $employee->salary ?? 0;
        $workingDays = $attendanceData['working_days'];
        $dailyRate = $baseSalary / $workingDays;
        $hourlyRate = $dailyRate / 8;

        // Calculate earnings
        $basicPay = $attendanceData['total_regular_hours'] * $hourlyRate;
        $overtimeRate = $hourlyRate * 1.25; // 25% premium
        $overtimePay = $attendanceData['total_overtime_hours'] * $overtimeRate;
        
        // Sales commission and performance bonus (TODO: integrate with sales data)
        $salesCommission = 0;
        $performanceBonus = 0;
        $otherAllowances = $employee->other_allowances ?? 0;
        
        $totalAllowances = $salesCommission + $performanceBonus + $otherAllowances;
        $grossSalary = $basicPay + $overtimePay + $totalAllowances;

        // Calculate deductions
        $sssContribution = $this->calculateSSS($baseSalary);
        $philhealthContribution = $this->calculatePhilHealth($baseSalary);
        $pagibigContribution = $this->calculatePagIbig($baseSalary);
        
        $totalStatutoryDeductions = $sssContribution + $philhealthContribution + $pagibigContribution;
        $withholdingTax = $this->calculateWithholdingTax($grossSalary, $totalStatutoryDeductions);
        
        // Absent deductions
        $absentDeductions = $attendanceData['total_absent_days'] * $dailyRate;
        
        // Undertime deductions
        $undertimeDeductions = $attendanceData['total_undertime_hours'] * $hourlyRate;
        
        // Loan deductions (TODO: integrate with loan system)
        $loanDeductions = 0;
        
        $otherDeductions = $absentDeductions + $undertimeDeductions;
        $totalDeductions = $withholdingTax + $sssContribution + $philhealthContribution + $pagibigContribution + $otherDeductions + $loanDeductions;
        
        $netSalary = $grossSalary - $totalDeductions;

        return [
            'base_salary' => round($baseSalary, 2),
            'basic_pay' => round($basicPay, 2),
            'overtime_pay' => round($overtimePay, 2),
            'sales_commission' => round($salesCommission, 2),
            'performance_bonus' => round($performanceBonus, 2),
            'other_allowances' => round($otherAllowances, 2),
            'total_allowances' => round($totalAllowances, 2),
            'gross_salary' => round($grossSalary, 2),
            'withholding_tax' => round($withholdingTax, 2),
            'sss_contribution' => round($sssContribution, 2),
            'philhealth_contribution' => round($philhealthContribution, 2),
            'pagibig_contribution' => round($pagibigContribution, 2),
            'absent_deductions' => round($absentDeductions, 2),
            'undertime_deductions' => round($undertimeDeductions, 2),
            'loan_deductions' => round($loanDeductions, 2),
            'other_deductions' => round($otherDeductions, 2),
            'total_deductions' => round($totalDeductions, 2),
            'net_salary' => round($netSalary, 2),
            'attendance_summary' => $attendanceData,
        ];
    }

    /**
     * Create payroll components from calculation.
     */
    protected function createPayrollComponents(Payroll $payroll, array $calculation): void
    {
        // Earnings
        if ($calculation['basic_pay'] > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Basic Pay',
                'amount' => $calculation['basic_pay'],
            ]);
        }
        
        if ($calculation['overtime_pay'] > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Overtime Pay',
                'amount' => $calculation['overtime_pay'],
            ]);
        }
        
        if ($calculation['other_allowances'] > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_EARNING,
                'component_name' => 'Allowances',
                'amount' => $calculation['other_allowances'],
            ]);
        }

        // Deductions
        PayrollComponent::create([
            'payroll_id' => $payroll->id,
            'component_type' => PayrollComponent::TYPE_DEDUCTION,
            'component_name' => 'Withholding Tax',
            'amount' => $calculation['withholding_tax'],
        ]);
        
        PayrollComponent::create([
            'payroll_id' => $payroll->id,
            'component_type' => PayrollComponent::TYPE_DEDUCTION,
            'component_name' => 'SSS Contribution',
            'amount' => $calculation['sss_contribution'],
        ]);
        
        PayrollComponent::create([
            'payroll_id' => $payroll->id,
            'component_type' => PayrollComponent::TYPE_DEDUCTION,
            'component_name' => 'PhilHealth Contribution',
            'amount' => $calculation['philhealth_contribution'],
        ]);
        
        PayrollComponent::create([
            'payroll_id' => $payroll->id,
            'component_type' => PayrollComponent::TYPE_DEDUCTION,
            'component_name' => 'Pag-IBIG Contribution',
            'amount' => $calculation['pagibig_contribution'],
        ]);
        
        if ($calculation['absent_deductions'] > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_DEDUCTION,
                'component_name' => 'Absent Deductions',
                'amount' => $calculation['absent_deductions'],
            ]);
        }
        
        if ($calculation['undertime_deductions'] > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'component_type' => PayrollComponent::TYPE_DEDUCTION,
                'component_name' => 'Undertime Deductions',
                'amount' => $calculation['undertime_deductions'],
            ]);
        }
    }

    /**
     * Calculate SSS contribution.
     */
    protected function calculateSSS(float $monthlySalary): float
    {
        if ($monthlySalary < 4250) return 180;
        if ($monthlySalary < 4750) return 202.50;
        if ($monthlySalary < 5250) return 225;
        if ($monthlySalary < 5750) return 247.50;
        if ($monthlySalary < 6250) return 270;
        if ($monthlySalary < 6750) return 292.50;
        if ($monthlySalary < 7250) return 315;
        if ($monthlySalary < 7750) return 337.50;
        if ($monthlySalary < 8250) return 360;
        if ($monthlySalary < 8750) return 382.50;
        if ($monthlySalary < 9250) return 405;
        if ($monthlySalary < 9750) return 427.50;
        if ($monthlySalary < 10250) return 450;
        if ($monthlySalary >= 30000) return 1350;
        return 900;
    }

    /**
     * Calculate PhilHealth contribution.
     */
    protected function calculatePhilHealth(float $monthlySalary): float
    {
        $rate = 0.025;
        $minSalary = 10000;
        $maxSalary = 100000;
        
        $baseSalary = min(max($monthlySalary, $minSalary), $maxSalary);
        return round($baseSalary * $rate, 2);
    }

    /**
     * Calculate Pag-IBIG contribution.
     */
    protected function calculatePagIbig(float $monthlySalary): float
    {
        if ($monthlySalary <= 1500) {
            return round($monthlySalary * 0.01, 2);
        }
        return min(round($monthlySalary * 0.02, 2), 100);
    }

    /**
     * Export payrolls to CSV.
     */
    protected function exportToCSV($payrolls, $period)
    {
        $filename = "payroll_batch_{$period}_" . date('Ymd') . ".csv";
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ];

        $callback = function() use ($payrolls) {
            $file = fopen('php://output', 'w');
            
            // Headers
            fputcsv($file, [
                'Employee ID', 'Employee Name', 'Department', 'Position',
                'Basic Pay', 'Overtime', 'Allowances', 'Gross Salary',
                'Deductions', 'Net Salary', 'Payment Method', 'Status'
            ]);
            
            // Data
            foreach ($payrolls as $payroll) {
                fputcsv($file, [
                    $payroll->employee->employee_id ?? $payroll->employee->id,
                    $payroll->employee->first_name . ' ' . $payroll->employee->last_name,
                    $payroll->employee->department,
                    $payroll->employee->position,
                    $payroll->base_salary,
                    $payroll->components->where('component_name', 'Overtime Pay')->first()->calculated_amount ?? 0,
                    $payroll->allowances,
                    $payroll->gross_salary,
                    $payroll->deductions,
                    $payroll->net_salary,
                    $payroll->payment_method,
                    $payroll->status,
                ]);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export payrolls to PDF (placeholder - requires PDF library).
     */
    protected function exportToPDF($payrolls, $period)
    {
        // TODO: Implement PDF export using dompdf or similar
        return response()->json([
            'message' => 'PDF export will be implemented',
            'payrolls' => $payrolls
        ]);
    }

    /**
     * Process payroll (mark as paid).
     */
    public function process(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payrollIds' => 'required|array',
            'payrollIds.*' => 'exists:payrolls,id',
            'paymentDate' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $processedCount = 0;
        $errors = [];

        foreach ($request->payrollIds as $payrollId) {
            try {
                $payroll = Payroll::forShopOwner($user->shop_owner_id)
                    ->findOrFail($payrollId);

                if ($payroll->status === 'paid') {
                    $errors[] = "Payroll for {$payroll->employee->fullName} is already paid";
                    continue;
                }

                $payroll->markAsPaid($request->paymentDate);
                $processedCount++;

            } catch (\Exception $e) {
                $errors[] = "Error processing payroll ID {$payrollId}: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Payroll processing completed',
            'processed' => $processedCount,
            'errors' => $errors
        ]);
    }

    /**
     * Get payroll statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $period = $request->get('period');

        $query = Payroll::forShopOwner($user->shop_owner_id);

        if ($period) {
            $query->forPeriod($period);
        }

        $totalPayrolls = $query->count();
        $pendingPayrolls = $query->pending()->count();
        $processedPayrolls = $query->processed()->count();
        $paidPayrolls = $query->withStatus('paid')->count();

        $totalAmount = $query->sum('netSalary');
        $pendingAmount = $query->pending()->sum('netSalary');
        $paidAmount = $query->withStatus('paid')->sum('netSalary');

        return response()->json([
            'totalPayrolls' => $totalPayrolls,
            'pendingPayrolls' => $pendingPayrolls,
            'processedPayrolls' => $processedPayrolls,
            'paidPayrolls' => $paidPayrolls,
            'totalAmount' => $totalAmount,
            'pendingAmount' => $pendingAmount,
            'paidAmount' => $paidAmount,
        ]);
    }

    /**
     * Export payslip as PDF.
     * 
     * Security: Validates role before allowing sensitive data export
     */
    public function exportPayslip(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            \Log::warning('Unauthorized payroll export attempt', [
                'user_id' => $user->id,
                'user_role' => $user->getRoleNames()->first(),
                'payroll_id' => $id
            ]);
            return response()->json([
                'error' => 'Unauthorized. You do not have permission to export payslips.'
            ], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee')
            ->findOrFail($id);

        // Audit logging
        \Log::info('Payroll exported', [
            'exporter_id' => $user->id,
            'exporter_role' => $user->getRoleNames()->first(),
            'payroll_id' => $id,
            'employee_id' => $payroll->employee_id
        ]);

        // TODO: Generate PDF payslip
        // $pdf = PDF::loadView('payroll.payslip', compact('payroll'));
        // return $pdf->download("payslip_{$payroll->employee->fullName}_{$payroll->payrollPeriod}.pdf");

        return response()->json([
            'message' => 'PDF generation not implemented yet',
            'payroll' => $payroll
        ]);
    }

    /**
     * Get attendance days for payroll period.
     */
    private function getAttendanceDays($employeeId, $period)
    {
        // Parse period (e.g., "2026-01" to get year and month)
        [$year, $month] = explode('-', $period);
        
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();

        return AttendanceRecord::forEmployee($employeeId)
            ->betweenDates($startDate->toDateString(), $endDate->toDateString())
            ->whereIn('status', ['present', 'late'])
            ->count();
    }
    
    /**
     * Get payroll components for a specific payroll.
     */
    public function getComponents(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)
            ->with(['components' => function($query) {
                $query->orderBy('component_type')->orderBy('component_name');
            }])
            ->findOrFail($id);

        $components = $payroll->components->groupBy('component_type');

        return response()->json([
            'payroll_id' => $payroll->id,
            'employee' => $payroll->employee,
            'components' => [
                'earnings' => $components->get(PayrollComponent::TYPE_EARNING, collect()),
                'deductions' => $components->get(PayrollComponent::TYPE_DEDUCTION, collect()),
                'benefits' => $components->get(PayrollComponent::TYPE_BENEFIT, collect()),
            ],
            'totals' => [
                'total_earnings' => $components->get(PayrollComponent::TYPE_EARNING, collect())->sum('calculated_amount'),
                'total_deductions' => $components->get(PayrollComponent::TYPE_DEDUCTION, collect())->sum('calculated_amount'),
                'total_benefits' => $components->get(PayrollComponent::TYPE_BENEFIT, collect())->sum('calculated_amount'),
                'gross_salary' => $payroll->gross_salary,
                'tax_amount' => $payroll->tax_amount,
                'net_salary' => $payroll->net_salary,
            ]
        ]);
    }
    
    /**
     * Add a custom component to existing payroll.
     */
    public function addComponent(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        // Only allow adding components to pending payrolls
        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot add components to non-pending payroll'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'component_type' => 'required|in:' . implode(',', [
                PayrollComponent::TYPE_EARNING,
                PayrollComponent::TYPE_DEDUCTION,
                PayrollComponent::TYPE_BENEFIT
            ]),
            'component_name' => 'required|string|max:100',
            'amount' => 'required|numeric',
            'is_taxable' => 'sometimes|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $component = PayrollComponent::create([
            'payroll_id' => $payroll->id,
            'shop_owner_id' => $user->shop_owner_id,
            'component_type' => $request->component_type,
            'component_name' => $request->component_name,
            'amount' => $request->amount,
            'calculation_method' => PayrollComponent::CALC_FIXED,
            'is_taxable' => $request->get('is_taxable', false),
            'description' => $request->description,
        ]);

        // Recalculate payroll totals
        $this->recalculateTotals($payroll);

        return response()->json([
            'message' => 'Component added successfully',
            'component' => $component,
            'payroll' => $payroll->fresh()
        ], 201);
    }
    
    /**
     * Update a payroll component.
     */
    public function updateComponent(Request $request, $payrollId, $componentId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($payrollId);

        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot update components of non-pending payroll'
            ], 422);
        }

        $component = PayrollComponent::where('payroll_id', $payrollId)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($componentId);

        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|required|numeric',
            'is_taxable' => 'sometimes|boolean',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('amount')) {
            $component->base_amount = $request->amount;
            $component->calculated_amount = $request->amount;
        }

        if ($request->has('is_taxable')) {
            $component->is_taxable = $request->is_taxable;
        }

        if ($request->has('description')) {
            $component->description = $request->description;
        }

        $component->save();

        // Recalculate payroll totals
        $this->recalculateTotals($payroll);

        return response()->json([
            'message' => 'Component updated successfully',
            'component' => $component,
            'payroll' => $payroll->fresh()
        ]);
    }
    
    /**
     * Delete a payroll component.
     */
    public function deleteComponent(Request $request, $payrollId, $componentId): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($payrollId);

        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot delete components from non-pending payroll'
            ], 422);
        }

        $component = PayrollComponent::where('payroll_id', $payrollId)
            ->where('shop_owner_id', $user->shop_owner_id)
            ->findOrFail($componentId);

        // Prevent deletion of core components (basic salary, etc.)
        if ($component->is_recurring && in_array($component->component_name, ['Basic Salary', 'Income Tax'])) {
            return response()->json([
                'error' => 'Cannot delete core payroll components'
            ], 422);
        }

        $component->delete();

        // Recalculate payroll totals
        $this->recalculateTotals($payroll);

        return response()->json([
            'message' => 'Component deleted successfully',
            'payroll' => $payroll->fresh()
        ]);
    }
    
    /**
     * Recalculate payroll using service.
     */
    public function recalculate(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payroll = Payroll::forShopOwner($user->shop_owner_id)->findOrFail($id);

        if ($payroll->status !== 'pending') {
            return response()->json([
                'error' => 'Cannot recalculate non-pending payroll'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'attendance_days' => 'sometimes|integer|min:0|max:31',
            'leave_days' => 'sometimes|integer|min:0|max:31',
            'overtime_hours' => 'sometimes|numeric|min:0|max:744',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $recalculated = $this->payrollService->recalculatePayroll(
                $payroll,
                $request->only(['attendance_days', 'leave_days', 'overtime_hours'])
            );

            return response()->json([
                'message' => 'Payroll recalculated successfully',
                'payroll' => $recalculated->load('components', 'employee')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Recalculation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get payroll summary for a period.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $summary = $this->payrollService->getPayrollSummary(
                $user->shop_owner_id,
                $request->period_start,
                $request->period_end
            );

            return response()->json($summary);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Summary generation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Calculate payroll preview based on attendance data.
     * This endpoint returns calculated values without saving to database.
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        // Check if user is Manager or has any HR-related permissions
        if (!$user->hasRole('Manager') && !$user->can('view-employees') && !$user->can('view-attendance') && !$user->can('view-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'regular_hours' => 'required|numeric|min:0',
            'overtime_hours' => 'nullable|numeric|min:0',
            'absent_days' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if employee belongs to the same shop owner
        $employee = Employee::forShopOwner($user->shop_owner_id)
            ->findOrFail($request->employee_id);

        $regularHours = $request->regular_hours;
        $overtimeHours = $request->overtime_hours ?? 0;
        $absentDays = $request->absent_days ?? 0;
        
        $monthlySalary = $employee->salary ?? 0;
        $workingDays = Carbon::parse($request->start_date)->diffInDays(Carbon::parse($request->end_date)) + 1;
        
        // Calculate rates
        $dailyRate = $workingDays > 0 ? $monthlySalary / $workingDays : 0;
        $hourlyRate = $dailyRate / 8;
        
        // Calculate earnings
        $basicPay = $regularHours * $hourlyRate;
        $overtimeRate = $hourlyRate * 1.25; // 25% overtime premium
        $overtimePay = $overtimeHours * $overtimeRate;
        
        // Sales commission and bonuses (from employee data if available)
        $salesCommission = 0; // Would come from sales data
        $performanceBonus = 0; // Would come from performance metrics
        $otherAllowances = 0; // Would come from employee allowances
        
        $grossPay = $basicPay + $overtimePay;
        $totalEarnings = $grossPay + $salesCommission + $performanceBonus + $otherAllowances;
        
        // Calculate deductions (Philippine law)
        $sssContribution = $this->calculateSSSContribution($monthlySalary);
        $philHealthContribution = $this->calculatePhilHealthContribution($monthlySalary);
        $pagIbigContribution = $this->calculatePagIbigContribution($monthlySalary);
        
        $totalStatutoryDeductions = $sssContribution + $philHealthContribution + $pagIbigContribution;
        $withholdingTax = $this->calculateMonthlyTax($grossPay, $totalStatutoryDeductions);
        
        // Absent deductions
        $absentDeductions = $absentDays * $dailyRate;
        
        $totalDeductions = $withholdingTax + $sssContribution + $philHealthContribution + $pagIbigContribution + $absentDeductions;
        $netPay = $totalEarnings - $totalDeductions;
        
        return response()->json([
            'calculation' => [
                'employee' => [
                    'id' => $employee->id,
                    'name' => "{$employee->first_name} {$employee->last_name}",
                    'monthly_salary' => $monthlySalary,
                ],
                'hours' => [
                    'regular_hours' => round($regularHours, 2),
                    'overtime_hours' => round($overtimeHours, 2),
                    'absent_days' => $absentDays,
                ],
                'earnings' => [
                    'basic_pay' => round($basicPay, 2),
                    'overtime_pay' => round($overtimePay, 2),
                    'sales_commission' => round($salesCommission, 2),
                    'performance_bonus' => round($performanceBonus, 2),
                    'other_allowances' => round($otherAllowances, 2),
                    'total_earnings' => round($totalEarnings, 2),
                ],
                'deductions' => [
                    'withholding_tax' => round($withholdingTax, 2),
                    'sss_contribution' => round($sssContribution, 2),
                    'philhealth_contribution' => round($philHealthContribution, 2),
                    'pagibig_contribution' => round($pagIbigContribution, 2),
                    'absent_deductions' => round($absentDeductions, 2),
                    'total_deductions' => round($totalDeductions, 2),
                ],
                'net_pay' => round($netPay, 2),
                'gross_pay' => round($grossPay, 2),
            ]
        ]);
    }
    
    /**
     * Calculate SSS contribution based on Philippine SSS table.
     */
    private function calculateSSSContribution(float $monthlySalary): float
    {
        if ($monthlySalary < 4250) return 180;
        if ($monthlySalary < 4750) return 202.50;
        if ($monthlySalary < 5250) return 225;
        if ($monthlySalary < 5750) return 247.50;
        if ($monthlySalary < 6250) return 270;
        if ($monthlySalary < 6750) return 292.50;
        if ($monthlySalary < 7250) return 315;
        if ($monthlySalary < 7750) return 337.50;
        if ($monthlySalary < 8250) return 360;
        if ($monthlySalary < 8750) return 382.50;
        if ($monthlySalary < 9250) return 405;
        if ($monthlySalary < 9750) return 427.50;
        if ($monthlySalary < 10250) return 450;
        if ($monthlySalary < 10750) return 472.50;
        if ($monthlySalary < 11250) return 495;
        if ($monthlySalary < 11750) return 517.50;
        if ($monthlySalary < 12250) return 540;
        if ($monthlySalary < 12750) return 562.50;
        if ($monthlySalary < 13250) return 585;
        if ($monthlySalary < 13750) return 607.50;
        if ($monthlySalary < 14250) return 630;
        if ($monthlySalary < 14750) return 652.50;
        if ($monthlySalary < 15250) return 675;
        if ($monthlySalary < 15750) return 697.50;
        if ($monthlySalary < 16250) return 720;
        if ($monthlySalary < 16750) return 742.50;
        if ($monthlySalary < 17250) return 765;
        if ($monthlySalary < 17750) return 787.50;
        if ($monthlySalary < 18250) return 810;
        if ($monthlySalary < 18750) return 832.50;
        if ($monthlySalary < 19250) return 855;
        if ($monthlySalary < 19750) return 877.50;
        if ($monthlySalary >= 30000) return 1350;
        return 900;
    }
    
    /**
     * Calculate PhilHealth contribution.
     */
    private function calculatePhilHealthContribution(float $monthlySalary): float
    {
        $rate = 0.025; // 2.5% employee share
        $minSalary = 10000;
        $maxSalary = 100000;
        
        $baseSalary = $monthlySalary;
        if ($baseSalary < $minSalary) $baseSalary = $minSalary;
        if ($baseSalary > $maxSalary) $baseSalary = $maxSalary;
        
        return round($baseSalary * $rate, 2);
    }
    
    /**
     * Calculate Pag-IBIG contribution.
     */
    private function calculatePagIbigContribution(float $monthlySalary): float
    {
        if ($monthlySalary <= 1500) {
            return round($monthlySalary * 0.01, 2);
        } else {
            $contribution = round($monthlySalary * 0.02, 2);
            return min($contribution, 100);
        }
    }
    
    /**
     * Calculate withholding tax.
     */
    private function calculateWithholdingTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 250000) return 0;
        if ($taxableIncome <= 400000) return ($taxableIncome - 250000) * 0.15;
        if ($taxableIncome <= 800000) return 22500 + ($taxableIncome - 400000) * 0.20;
        if ($taxableIncome <= 2000000) return 102500 + ($taxableIncome - 800000) * 0.25;
        if ($taxableIncome <= 8000000) return 402500 + ($taxableIncome - 2000000) * 0.30;
        return 2202500 + ($taxableIncome - 8000000) * 0.35;
    }
    
    /**
     * Calculate monthly tax.
     */
    private function calculateMonthlyTax(float $monthlyGrossPay, float $monthlyDeductions): float
    {
        $monthlyTaxableIncome = $monthlyGrossPay - $monthlyDeductions;
        $annualTaxableIncome = $monthlyTaxableIncome * 12;
        $annualTax = $this->calculateWithholdingTax($annualTaxableIncome);
        $monthlyTax = $annualTax / 12;
        return round($monthlyTax, 2);
    }
    
    /**
     * Helper method to recalculate payroll totals.
     */
    private function recalculateTotals(Payroll $payroll): void
    {
        $components = $payroll->components;
        
        $earnings = $components->where('component_type', PayrollComponent::TYPE_EARNING)->sum('calculated_amount');
        $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION)->sum('calculated_amount');
        $benefits = $components->where('component_type', PayrollComponent::TYPE_BENEFIT)->sum('calculated_amount');
        
        $grossPay = $earnings + $benefits;
        
        // Recalculate tax on taxable components
        $taxableAmount = $components->where('is_taxable', true)->sum('calculated_amount');
        $taxAmount = $this->payrollService->calculateTax($payroll->shop_owner_id, $taxableAmount);
        
        $netPay = $grossPay - $deductions - $taxAmount;
        
        $payroll->update([
            'gross_salary' => $grossPay,
            'total_deductions' => $deductions,
            'tax_amount' => $taxAmount,
            'net_salary' => $netPay,
        ]);
        
        // Update or create tax component
        $taxComponent = $components->firstWhere('component_name', 'Income Tax');
        if ($taxComponent) {
            $taxComponent->update(['calculated_amount' => $taxAmount]);
        } elseif ($taxAmount > 0) {
            PayrollComponent::create([
                'payroll_id' => $payroll->id,
                'shop_owner_id' => $payroll->shop_owner_id,
                'component_type' => PayrollComponent::TYPE_DEDUCTION,
                'component_name' => 'Income Tax',
                'base_amount' => 0,
                'calculation_method' => PayrollComponent::METHOD_CUSTOM,
                'calculated_amount' => $taxAmount,
                'is_taxable' => false,
                'is_recurring' => true,
                'description' => 'Progressive income tax'
            ]);
        }
    }

    /**
     * Get payslips awaiting finance approval
     */
    public function getPayslipsForApproval(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = Payroll::forShopOwner($user->shop_owner_id)
            ->where('status', 'pending')
            ->with('employee:id,first_name,last_name,department,designation');

        // Apply filters
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('payroll_period', 'like', "%{$searchTerm}%")
                  ->orWhereHas('employee', function ($empQuery) use ($searchTerm) {
                      $empQuery->where('first_name', 'like', "%{$searchTerm}%")
                              ->orWhere('last_name', 'like', "%{$searchTerm}%");
                  });
            });
        }

        if ($request->filled('status')) {
            $query->where('approval_status', $request->status);
        }

        $payslips = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);

        return response()->json($payslips);
    }

    /**
     * Get single payslip for approval
     */
    public function getPayslipForApproval(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)
            ->with('employee:id,first_name,last_name,department,designation', 'components')
            ->find($id);

        if (!$payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        // Calculate totals
        $components = $payslip->components;
        $earnings = $components->where('component_type', PayrollComponent::TYPE_EARNING)->sum('calculated_amount');
        $deductions = $components->where('component_type', PayrollComponent::TYPE_DEDUCTION)->sum('calculated_amount');
        $benefits = $components->where('component_type', PayrollComponent::TYPE_BENEFIT)->sum('calculated_amount');

        return response()->json([
            'id' => $payslip->id,
            'employee_name' => $payslip->employee->first_name . ' ' . $payslip->employee->last_name,
            'employee_id' => $payslip->employee_id,
            'department' => $payslip->employee->department,
            'role' => $payslip->employee->designation,
            'pay_period' => $payslip->payroll_period,
            'generated_date' => $payslip->created_at->format('Y-m-d'),
            'generated_by' => 'HR Payroll',
            'gross_pay' => $payslip->gross_salary,
            'deductions' => $payslip->total_deductions,
            'net_pay' => $payslip->net_salary,
            'tax_amount' => $payslip->tax_amount ?? 0,
            'status' => $payslip->approval_status ?? 'pending',
            'notes' => $payslip->notes ?? '',
            'line_items' => $components->map(function ($component) {
                return [
                    'label' => $component->component_name,
                    'amount' => $component->calculated_amount,
                    'type' => $component->component_type,
                ];
            })->toArray(),
        ]);
    }

    /**
     * Approve payslip (Finance approval)
     */
    public function approvePayslip(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($id);

        if (!$payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->approval_status === 'approved') {
            return response()->json(['error' => 'Payslip already approved'], 400);
        }

        try {
            $payslip->update([
                'approval_status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $request->notes ?? '',
            ]);

            // Log the approval
            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_approved',
                'Payslip Approved',
                "Payslip #{$payslip->id} approved by {$user->name}",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip approved successfully',
                'payslip' => $payslip,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to approve payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject payslip (Finance rejection)
     */
    public function rejectPayslip(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'required|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($id);

        if (!$payslip) {
            return response()->json(['error' => 'Payslip not found'], 404);
        }

        if ($payslip->approval_status === 'rejected') {
            return response()->json(['error' => 'Payslip already rejected'], 400);
        }

        try {
            $payslip->update([
                'approval_status' => 'rejected',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $request->notes,
            ]);

            // Log the rejection
            $this->logHRActivity(
                $user->shop_owner_id,
                'payslip_rejected',
                'Payslip Rejected',
                "Payslip #{$payslip->id} rejected by {$user->name}: {$request->notes}",
                $payslip
            );

            return response()->json([
                'message' => 'Payslip rejected and sent back to HR for correction',
                'payslip' => $payslip,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reject payslip: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Preview batch approval - get summary before approving all
     */
    public function batchApprovalPreview(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        try {
            $payslips = Payroll::forShopOwner($user->shop_owner_id)
                ->where('approval_status', 'pending')
                ->with(['employee'])
                ->get();

            $previews = $payslips->map(function ($payslip) {
                return [
                    'id' => $payslip->id,
                    'employee_id' => $payslip->employee_id,
                    'employee_name' => $payslip->employee->first_name . ' ' . $payslip->employee->last_name,
                    'department' => $payslip->employee->department,
                    'pay_period' => $payslip->payroll_period,
                    'gross_pay' => $payslip->gross_salary,
                    'net_pay' => $payslip->net_salary,
                    'status' => $payslip->approval_status,
                ];
            });

            return response()->json([
                'previews' => $previews,
                'summary' => [
                    'count' => $payslips->count(),
                    'total_gross' => $payslips->sum('gross_salary'),
                    'total_net' => $payslips->sum('net_salary'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to load preview: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Batch approve multiple payslips
     */
    public function batchApprove(Request $request): JsonResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user->can('approve-payroll')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payslip_ids' => 'required|array',
            'payslip_ids.*' => 'required|integer|exists:payrolls,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $approvedCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($request->payslip_ids as $payslipId) {
                try {
                    $payslip = Payroll::forShopOwner($user->shop_owner_id)->find($payslipId);

                    if (!$payslip) {
                        $errors[] = "Payslip #{$payslipId} not found";
                        $failedCount++;
                        continue;
                    }

                    if ($payslip->approval_status !== 'pending') {
                        $errors[] = "Payslip #{$payslipId} is not pending";
                        $failedCount++;
                        continue;
                    }

                    $payslip->update([
                        'approval_status' => 'approved',
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                        'approval_notes' => $request->notes ?? 'Batch approved',
                    ]);

                    // Log the approval
                    $this->logHRActivity(
                        $user->shop_owner_id,
                        'payslip_approved',
                        'Payslip Batch Approved',
                        "Payslip #{$payslip->id} approved by {$user->name} (batch)",
                        $payslip
                    );

                    $approvedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to approve payslip #{$payslipId}: " . $e->getMessage();
                    $failedCount++;
                }
            }

            return response()->json([
                'message' => "Approved {$approvedCount} payslip(s) successfully",
                'approved' => $approvedCount,
                'failed' => $failedCount,
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Batch approval failed: ' . $e->getMessage()], 500);
        }
    }
}
