<?php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RepairRequest;
use App\Models\User;

$latest = RepairRequest::query()
    ->where('request_id', 'like', 'REP-POS-%')
    ->orderByDesc('id')
    ->limit(5)
    ->get(['id','request_id','shop_owner_id','assigned_repairer_id','status','manual_pos_queue_enabled','created_at']);

echo "LATEST_REP_POS\n";
foreach ($latest as $r) {
    echo json_encode($r->toArray()) . PHP_EOL;
}

$shopId = (int)($latest->first()->shop_owner_id ?? 0);
echo "SHOP_ID={$shopId}\n";
if ($shopId > 0) {
    $roleCount = User::query()
        ->where('shop_owner_id', $shopId)
        ->where('status', 'active')
        ->whereHas('roles', function ($q) { $q->where('name', 'Repairer'); })
        ->count();

    $permCount = User::query()
        ->where('shop_owner_id', $shopId)
        ->where('status', 'active')
        ->whereHas('permissions', function ($q) { $q->where('name', 'like', '%repair%'); })
        ->count();

    echo "ROLE_REPAIRER_COUNT={$roleCount}\n";
    echo "PERM_REPAIR_COUNT={$permCount}\n";

    $users = User::query()
        ->where('shop_owner_id', $shopId)
        ->where('status', 'active')
        ->with(['roles:id,name','permissions:id,name'])
        ->limit(20)
        ->get();

    echo "ACTIVE_USERS\n";
    foreach ($users as $u) {
        echo json_encode([
            'id' => $u->id,
            'name' => $u->name,
            'roles' => $u->roles->pluck('name')->values(),
            'perms' => $u->permissions->pluck('name')->take(8)->values(),
        ]) . PHP_EOL;
    }
}
