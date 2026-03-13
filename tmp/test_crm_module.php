<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Conversation;

// ─── IDs ──────────────────────────────────────────────────────────────────────
$customerId = 167;
$orderId    = 1;
$repairId   = DB::table('repair_requests')->where('request_id', 'CRM-TEST-REP-001')->value('id') ?? 2;
$reviewId   = DB::table('customer_reviews')->where('customer_id', $customerId)->where('shop_owner_id', 1)->value('id') ?? 1;
$convId     = DB::table('conversations')->where('shop_owner_id', 1)->value('id') ?? 1;
$conv       = Conversation::find($convId);

echo "IDs: customer=$customerId order=$orderId repair=$repairId review=$reviewId conv=$convId\n\n";

$pass    = true;
$results = [];

function check(string $label, bool $condition, array &$results, bool &$pass, string $extra = ''): void
{
    $status = $condition ? 'PASS' : 'FAIL';
    if (!$condition) $pass = false;
    $results[] = [$status, $label, $extra];
}

// ─── 1. Login on BOTH guards (controllers use Auth::user() = default web guard)
echo "[1] Logging in as CRM employee...\n";
$crmUser = User::where('email', 'crm.1@solespace.com')->first();
Auth::guard('user')->login($crmUser);   // for controllers using Auth::guard('user')
Auth::guard('web')->login($crmUser);    // for controllers using Auth::user()
$authed = Auth::guard('user')->check() && Auth::guard('web')->check();
check('CRM employee login (both guards)', $authed, $results, $pass,
    "user_id={$crmUser->id} shop_owner_id={$crmUser->shop_owner_id} role={$crmUser->role}");

// Shared helpers
$dashCtrl   = app(\App\Http\Controllers\API\CRM\CRMDashboardController::class);
$custCtrl   = app(\App\Http\Controllers\API\CRM\CRMCustomerController::class);
$convCtrl   = app(\App\Http\Controllers\API\CRM\ConversationController::class);
$revCtrl    = app(\App\Http\Controllers\API\CRM\CRMReviewController::class);

// ─── 2. Dashboard stats ────────────────────────────────────────────────────────
echo "[2] Dashboard stats...\n";
try {
    $response = $dashCtrl->index();
    $data = json_decode($response->getContent(), true);
    check('GET /api/crm/dashboard-stats', isset($data['active_customers']), $results, $pass,
        "active={$data['active_customers']} open_conv={$data['open_conversations']} pending_reviews={$data['pending_reviews']} avg=" . round($data['average_rating'] ?? 0, 2));
} catch (\Throwable $e) {
    check('GET /api/crm/dashboard-stats', false, $results, $pass, $e->getMessage());
}

// ─── 3. Customer list ──────────────────────────────────────────────────────────
echo "[3] Customer list...\n";
try {
    $req = Request::create('/api/crm/customers', 'GET');
    $response = $custCtrl->index($req);
    $data = json_decode($response->getContent(), true);
    $total = $data['total'] ?? ($data['meta']['total'] ?? count($data['data'] ?? []));
    check('GET /api/crm/customers', $response->getStatusCode() === 200, $results, $pass, "total=$total");
} catch (\Throwable $e) {
    check('GET /api/crm/customers', false, $results, $pass, $e->getMessage());
}

// ─── 4. Customer detail (lazy load) ───────────────────────────────────────────
echo "[4] Customer detail...\n";
try {
    $response = $custCtrl->show($customerId);
    $data = json_decode($response->getContent(), true);
    check('GET /api/crm/customers/{id}', $response->getStatusCode() === 200, $results, $pass,
        "orders=" . count($data['orders'] ?? []) .
        " repairs=" . count($data['repairs'] ?? []) .
        " notes=" . count($data['notes'] ?? []));
} catch (\Throwable $e) {
    check('GET /api/crm/customers/{id}', false, $results, $pass, $e->getMessage());
}

// ─── 5. Update customer ────────────────────────────────────────────────────────
echo "[5] Update customer...\n";
try {
    $req = Request::create("/api/crm/customers/$customerId", 'PUT', [
        'name'    => 'Miguel Dela Rosa',
        'phone'   => '09112345678',
        'address' => '123 Test Street, Manila',
        'status'  => 'active',
    ]);
    $response = $custCtrl->update($req, $customerId);
    $data = json_decode($response->getContent(), true);
    check('PUT /api/crm/customers/{id}', $response->getStatusCode() === 200, $results, $pass,
        "phone=" . ($data['customer']['phone'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('PUT /api/crm/customers/{id}', false, $results, $pass, $e->getMessage());
}

// ─── 6. Add customer note ─────────────────────────────────────────────────────
echo "[6] Add note...\n";
try {
    $req = Request::create("/api/crm/customers/$customerId/notes", 'POST', [
           'content' => 'CRM automated test note - ' . now()->toDateTimeString(),
    ]);
    $response = $custCtrl->storeNote($req, $customerId);
    $data = json_decode($response->getContent(), true);
    check('POST /api/crm/customers/{id}/notes', $response->getStatusCode() === 201, $results, $pass,
        "note_id=" . ($data['note']['id'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('POST /api/crm/customers/{id}/notes', false, $results, $pass, $e->getMessage());
}

// ─── 7. Conversations list ─────────────────────────────────────────────────────
echo "[7] Conversation list...\n";
try {
    $req = Request::create('/api/crm/conversations', 'GET');
    $response = $convCtrl->index($req);
    $data = json_decode($response->getContent(), true);
    $convs = $data['data'] ?? [];
    check('GET /api/crm/conversations', $response->getStatusCode() === 200, $results, $pass,
        "count=" . count($convs) . " total=" . ($data['total'] ?? '?'));
} catch (\Throwable $e) {
    check('GET /api/crm/conversations', false, $results, $pass, $e->getMessage());
}

// ─── 8. Conversation detail ───────────────────────────────────────────────────
echo "[8] Conversation detail...\n";
try {
    $response = $convCtrl->show($conv);
    $data = json_decode($response->getContent(), true);
    check('GET /api/crm/conversations/{id}', $response->getStatusCode() === 200, $results, $pass,
        "status=" . ($data['conversation']['status'] ?? $data['status'] ?? '?') .
        " messages=" . count($data['messages'] ?? []));
} catch (\Throwable $e) {
    check('GET /api/crm/conversations/{id}', false, $results, $pass, $e->getMessage());
}

// ─── 9. Send message ──────────────────────────────────────────────────────────
echo "[9] Send message...\n";
try {
    $req = Request::create("/api/crm/conversations/$convId/messages", 'POST', [
        'content' => 'Hello! We are looking into your request right away. Thank you for contacting us.',
    ]);
    $response = $convCtrl->sendMessage($req, $conv);
    $data = json_decode($response->getContent(), true);
    check('POST /api/crm/conversations/{id}/messages', in_array($response->getStatusCode(), [200, 201]), $results, $pass,
        isset($data['data']) ? "msg_id=" . ($data['data']['id'] ?? 'ok') . " sender=" . ($data['data']['sender_type'] ?? '?') : json_encode($data));
} catch (\Throwable $e) {
    check('POST /api/crm/conversations/{id}/messages', false, $results, $pass, $e->getMessage());
}

// ─── 10. Update conversation status → in_progress ────────────────────────────
echo "[10] Conversation status → in_progress...\n";
$conv->refresh();   // re-load after sendMessage may have changed it
try {
    $req = Request::create("/api/crm/conversations/$convId/status", 'PATCH', ['status' => 'in_progress']);
    $response = $convCtrl->updateStatus($req, $conv);
    $data = json_decode($response->getContent(), true);
    check('PATCH /api/crm/conversations/{id}/status → in_progress', $response->getStatusCode() === 200, $results, $pass,
        "status=" . ($data['conversation']['status'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('PATCH /api/crm/conversations/{id}/status → in_progress', false, $results, $pass, $e->getMessage());
}

// ─── 11. Transfer to Repairer ─────────────────────────────────────────────────
echo "[11] Transfer to repairer...\n";
$conv->refresh();
try {
    $req = Request::create("/api/crm/conversations/$convId/transfer", 'POST', [
        'to_department' => 'repairer',
        'transfer_note' => 'Customer reported sole detachment. Transferring to repair department for assessment.',
    ]);
    $response = $convCtrl->transfer($req, $conv);
    $data = json_decode($response->getContent(), true);
    check('POST /api/crm/conversations/{id}/transfer', $response->getStatusCode() === 200, $results, $pass,
        $data['message'] ?? json_encode($data));
} catch (\Throwable $e) {
    check('POST /api/crm/conversations/{id}/transfer', false, $results, $pass, $e->getMessage());
}

// ─── 12. Resolve conversation ─────────────────────────────────────────────────
echo "[12] Resolve conversation...\n";
$conv->refresh();
try {
    $req = Request::create("/api/crm/conversations/$convId/status", 'PATCH', ['status' => 'resolved']);
    $response = $convCtrl->updateStatus($req, $conv);
    $data = json_decode($response->getContent(), true);
    check('PATCH /api/crm/conversations/{id}/status → resolved', $response->getStatusCode() === 200, $results, $pass,
        "status=" . ($data['conversation']['status'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('PATCH /api/crm/conversations/{id}/status → resolved', false, $results, $pass, $e->getMessage());
}

// ─── 13. Review list ──────────────────────────────────────────────────────────
echo "[13] Review list...\n";
try {
    $req = Request::create('/api/crm/reviews', 'GET');
    $response = $revCtrl->index($req);
    $data = json_decode($response->getContent(), true);
    $total = $data['total'] ?? count($data['data'] ?? []);
    check('GET /api/crm/reviews', $response->getStatusCode() === 200, $results, $pass,
        "total=$total pending=" . ($data['stats']['pending'] ?? $data['stats']['by_status']['pending'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('GET /api/crm/reviews', false, $results, $pass, $e->getMessage());
}

// ─── 14. Mark review in_progress ─────────────────────────────────────────────
echo "[14] Review status → in_progress...\n";
try {
    $req = Request::create("/api/crm/reviews/$reviewId/status", 'PATCH', ['status' => 'in_progress']);
    $response = $revCtrl->updateStatus($req, $reviewId);
    $data = json_decode($response->getContent(), true);
    check('PATCH /api/crm/reviews/{id}/status → in_progress', $response->getStatusCode() === 200, $results, $pass,
        "status=" . ($data['review']['response_status'] ?? 'n/a'));
} catch (\Throwable $e) {
    check('PATCH /api/crm/reviews/{id}/status → in_progress', false, $results, $pass, $e->getMessage());
}

// ─── 15. Respond to review ────────────────────────────────────────────────────
echo "[15] Respond to review...\n";
try {
    $req = Request::create("/api/crm/reviews/$reviewId/respond", 'POST', [
        'staff_response' => 'Thank you for your feedback, Miguel! We are glad to assist. Please reach out if you need anything else.',
    ]);
    $response = $revCtrl->respond($req, $reviewId);
    $data = json_decode($response->getContent(), true);
    check('POST /api/crm/reviews/{id}/respond', $response->getStatusCode() === 200, $results, $pass,
        "status=" . ($data['review']['response_status'] ?? 'n/a') .
        " responded_at=" . ($data['review']['responded_at'] ? 'SET' : 'NULL'));
} catch (\Throwable $e) {
    check('POST /api/crm/reviews/{id}/respond', false, $results, $pass, $e->getMessage());
}

// ─── 16. Dashboard stats after all write ops ──────────────────────────────────
echo "[16] Dashboard stats (post-write)...\n";
try {
    $response = $dashCtrl->index();
    $data = json_decode($response->getContent(), true);
    check('GET /api/crm/dashboard-stats (post-write)', isset($data['active_customers']), $results, $pass,
        "active={$data['active_customers']} open_conv={$data['open_conversations']} pending_reviews={$data['pending_reviews']} avg=" . round($data['average_rating'] ?? 0, 2));
} catch (\Throwable $e) {
    check('GET /api/crm/dashboard-stats (post-write)', false, $results, $pass, $e->getMessage());
}

// ─── RESULTS ──────────────────────────────────────────────────────────────────
$lineLen = 72;
echo "\n" . str_repeat('═', $lineLen) . "\n";
echo "  CRM MODULE TEST RESULTS\n";
echo str_repeat('═', $lineLen) . "\n";
$passCount = 0;
$failCount = 0;
foreach ($results as [$status, $label, $extra]) {
    $icon = $status === 'PASS' ? '[PASS]' : '[FAIL]';
    if ($status === 'PASS') $passCount++; else $failCount++;
    $line = "  $icon  $label";
    if ($extra) $line .= "\n         $extra";
    echo $line . "\n";
}
echo str_repeat('─', $lineLen) . "\n";
echo "  TOTAL: $passCount passed, $failCount failed" . ($failCount === 0 ? " ✓ ALL GREEN" : " ← see failures above") . "\n";
echo str_repeat('═', $lineLen) . "\n";
