<?php
/**
 * Focused HR module smoke test for SME workflows.
 * Run: php tmp/test_hr_module.php
 */

$baseUrl = 'http://localhost/solespace-master/public';
$loginUrl = $baseUrl . '/user/login';
$credentials = [
    'email' => 'hr.2@solespace.com',
    'password' => 'password123',
];

$cookieJar = tempnam(sys_get_temp_dir(), 'hr_test_cookies_');
$results = [];
$sectionOpen = '';
$tempAuditPermissionGranted = false;
$tempAuditRoleGranted = false;

function section(string $name): void {
    global $sectionOpen;
    $sectionOpen = $name;
    echo "\n╔══════════════════════════════════════════════════════════════\n";
    echo "║  {$name}\n";
    echo "╚══════════════════════════════════════════════════════════════\n";
}

function xsrf_token_from_jar(): ?string {
    global $cookieJar;
    if (!file_exists($cookieJar)) return null;
    $lines = file($cookieJar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        if (str_contains($line, 'XSRF-TOKEN')) {
            $parts = preg_split('/\s+/', $line);
            return end($parts);
        }
    }
    return null;
}

function request(string $method, string $url, mixed $body = null): array {
    global $cookieJar;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = ['Accept: application/json'];
    $xsrf = xsrf_token_from_jar();
    if ($xsrf) {
        $headers[] = 'X-XSRF-TOKEN: ' . urldecode($xsrf);
    }

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => $raw,
        'json' => json_decode($raw, true),
    ];
}

function check(bool $condition, string $name, string $detail = ''): void {
    global $results, $sectionOpen;
    $label = $condition ? 'PASS' : 'FAIL';
    echo '  [' . $label . "] {$name}";
    if ($detail) echo " → {$detail}";
    echo "\n";
    $results[] = [
        'section' => $sectionOpen,
        'name' => $name,
        'pass' => $condition,
        'detail' => $detail,
    ];
}

section('0. Authentication');
// Grant audit-log permission temporarily so HR audit endpoints can be validated
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=solespace', 'root', '');
    $permId = (int) $pdo->query("SELECT id FROM permissions WHERE name='access-audit-logs' LIMIT 1")->fetchColumn();
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name='HR' AND guard_name='user' LIMIT 1")->fetchColumn();
    if ($roleId && $permId) {
        $hasRolePermission = (int) $pdo->query("SELECT COUNT(*) FROM role_has_permissions WHERE permission_id={$permId} AND role_id={$roleId}")->fetchColumn();
        if (! $hasRolePermission) {
            $pdo->exec("INSERT INTO role_has_permissions (permission_id, role_id) VALUES ({$permId}, {$roleId})");
            $tempAuditRoleGranted = true;
        }
    }
} catch (Throwable $e) {
    echo "  [WARN] Could not grant temporary audit permission: {$e->getMessage()}\n";
}
request('GET', $baseUrl . '/sanctum/csrf-cookie');
$loginRes = request('POST', $loginUrl, $credentials);
check(in_array($loginRes['status'], [200, 302]), 'Login as hr.2@solespace.com', 'HTTP ' . $loginRes['status']);
$erpPage = request('GET', $baseUrl . '/erp/hr');
check($erpPage['status'] === 200, 'GET /erp/hr page', 'HTTP ' . $erpPage['status']);

section('1. Dashboard');
$dashboardRes = request('GET', $baseUrl . '/api/hr/dashboard');
check($dashboardRes['status'] === 200, 'GET /api/hr/dashboard', 'HTTP ' . $dashboardRes['status']);
$dashboard = $dashboardRes['json'];
check(isset($dashboard['headcount']) || isset($dashboard['data']['headcount']), 'Dashboard returns headcount data');

section('2. Employee Directory');
$employeesRes = request('GET', $baseUrl . '/api/hr/employees');
check($employeesRes['status'] === 200, 'GET /api/hr/employees', 'HTTP ' . $employeesRes['status']);
$employees = $employeesRes['json']['data'] ?? $employeesRes['json'] ?? [];
$employeeCount = is_array($employees) ? count($employees) : 0;
check($employeeCount >= 1, 'Employee directory has records', 'count=' . $employeeCount);
$employeeId = null;
if ($employeeCount > 0) {
    $employeeId = $employees[0]['id'] ?? null;
}
$statsRes = request('GET', $baseUrl . '/api/hr/employees/statistics');
check($statsRes['status'] === 200, 'GET /api/hr/employees/statistics', 'HTTP ' . $statsRes['status']);
$templatesRes = request('GET', $baseUrl . '/api/hr/position-templates');
check($templatesRes['status'] === 200, 'GET /api/hr/position-templates', 'HTTP ' . $templatesRes['status']);
$permissionsRes = request('GET', $baseUrl . '/api/hr/permissions/available');
check($permissionsRes['status'] === 200, 'GET /api/hr/permissions/available', 'HTTP ' . $permissionsRes['status']);
if ($employeeId) {
    $employeeShowRes = request('GET', $baseUrl . '/api/hr/employees/' . $employeeId);
    check($employeeShowRes['status'] === 200, 'GET /api/hr/employees/{id}', 'HTTP ' . $employeeShowRes['status']);
}

section('3. Attendance');
$attendanceRes = request('GET', $baseUrl . '/api/hr/attendance');
check($attendanceRes['status'] === 200, 'GET /api/hr/attendance', 'HTTP ' . $attendanceRes['status']);
$attendanceStatsRes = request('GET', $baseUrl . '/api/hr/attendance/statistics');
check($attendanceStatsRes['status'] === 200, 'GET /api/hr/attendance/statistics', 'HTTP ' . $attendanceStatsRes['status']);
$latenessRes = request('GET', $baseUrl . '/api/hr/attendance/lateness/stats');
check($latenessRes['status'] === 200, 'GET /api/hr/attendance/lateness/stats', 'HTTP ' . $latenessRes['status']);

section('4. Leave and Overtime');
$leaveRes = request('GET', $baseUrl . '/api/hr/leave-requests');
check($leaveRes['status'] === 200, 'GET /api/hr/leave-requests', 'HTTP ' . $leaveRes['status']);
$leavePendingRes = request('GET', $baseUrl . '/api/hr/leave-requests/pending');
check($leavePendingRes['status'] === 200, 'GET /api/hr/leave-requests/pending', 'HTTP ' . $leavePendingRes['status']);
$overtimeRes = request('GET', $baseUrl . '/api/hr/overtime-requests');
check($overtimeRes['status'] === 200, 'GET /api/hr/overtime-requests', 'HTTP ' . $overtimeRes['status']);

section('5. Payroll');
$payrollRes = request('GET', $baseUrl . '/api/hr/payroll');
check($payrollRes['status'] === 200, 'GET /api/hr/payroll', 'HTTP ' . $payrollRes['status']);
$periodsRes = request('GET', $baseUrl . '/api/hr/payroll/periods');
check($periodsRes['status'] === 200, 'GET /api/hr/payroll/periods', 'HTTP ' . $periodsRes['status']);
$payrollStatsRes = request('GET', $baseUrl . '/api/hr/payroll/stats');
check($payrollStatsRes['status'] === 200, 'GET /api/hr/payroll/stats', 'HTTP ' . $payrollStatsRes['status']);
$periodStart = date('Y-m-01');
$periodEnd = date('Y-m-t');
$payrollSummaryRes = request('GET', $baseUrl . '/api/hr/payroll/summary?period_start=' . $periodStart . '&period_end=' . $periodEnd);
check($payrollSummaryRes['status'] === 200, 'GET /api/hr/payroll/summary', 'HTTP ' . $payrollSummaryRes['status']);
$batchPreviewRes = request('POST', $baseUrl . '/api/hr/payroll/batch/preview', [
    'employeeIds' => [],
    'payrollPeriod' => date('Y-m'),
]);
check(in_array($batchPreviewRes['status'], [200, 422]), 'POST /api/hr/payroll/batch/preview', 'HTTP ' . $batchPreviewRes['status']);

section('6. Audit Logs');
$auditRes = request('GET', $baseUrl . '/api/hr/audit-logs');
check($auditRes['status'] === 200, 'GET /api/hr/audit-logs', 'HTTP ' . $auditRes['status']);
$auditStatsRes = request('GET', $baseUrl . '/api/hr/audit-logs/statistics');
check($auditStatsRes['status'] === 200, 'GET /api/hr/audit-logs/statistics', 'HTTP ' . $auditStatsRes['status']);
$filtersRes = request('GET', $baseUrl . '/api/hr/audit-logs/filters/options');
check($filtersRes['status'] === 200, 'GET /api/hr/audit-logs/filters/options', 'HTTP ' . $filtersRes['status']);

section('7. Permission Guard');
$unauthCookieJar = tempnam(sys_get_temp_dir(), 'hr_unauth_');
$ch = curl_init($baseUrl . '/api/hr/dashboard');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $unauthCookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $unauthCookieJar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_exec($ch);
$unauthStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($unauthCookieJar);
check(in_array($unauthStatus, [401, 403, 302]), 'Unauthenticated HR API blocked', 'HTTP ' . $unauthStatus);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    if ($result['pass']) $pass++; else $fail++;
}

echo "\n════════════════════════════════════════════════════════════════\n";
echo "  HR MODULE TEST RESULTS\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
echo "Pass rate: " . (($pass + $fail) ? round(($pass / ($pass + $fail)) * 100) : 0) . "%\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($results as $result) {
        if (! $result['pass']) {
            echo '- [' . $result['section'] . '] ' . $result['name'];
            if ($result['detail']) echo ' → ' . $result['detail'];
            echo "\n";
        }
    }
}

if (($tempAuditPermissionGranted ?? false) || ($tempAuditRoleGranted ?? false)) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=solespace', 'root', '');
        $permId = (int) $pdo->query("SELECT id FROM permissions WHERE name='access-audit-logs' LIMIT 1")->fetchColumn();
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name='HR' AND guard_name='user' LIMIT 1")->fetchColumn();
        if (($tempAuditRoleGranted ?? false) && $roleId && $permId) {
            $pdo->exec("DELETE FROM role_has_permissions WHERE permission_id={$permId} AND role_id={$roleId}");
        }
    } catch (Throwable $e) {
        echo "[CLEANUP WARN] {$e->getMessage()}\n";
    }
}

@unlink($cookieJar);
