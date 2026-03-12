<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$so = App\Models\ShopOwner::first();
if ($so) {
    echo 'Shop Owner ID: ' . $so->id . PHP_EOL;
    echo 'Business Type: ' . $so->business_type . PHP_EOL;
    echo 'Workload Limit: ' . ($so->repair_workload_limit ?? 20) . PHP_EOL;

    $active = App\Models\RepairRequest::where('shop_owner_id', $so->id)
        ->whereIn('status', ['pending','received','in_progress','in-progress','assigned_to_repairer','repairer_accepted','awaiting_parts','waiting_customer_confirmation'])
        ->count();
    $done = App\Models\RepairRequest::where('shop_owner_id', $so->id)
        ->whereIn('status', ['completed','ready_for_pickup','picked_up'])
        ->count();
    $total = App\Models\RepairRequest::where('shop_owner_id', $so->id)->count();

    echo 'Active repairs: ' . $active . PHP_EOL;
    echo 'Completed repairs: ' . $done . PHP_EOL;
    echo 'Total repair requests: ' . $total . PHP_EOL;

    // Test service revenue query
    $services = Illuminate\Support\Facades\DB::table('repair_request_service as rrs')
        ->join('repair_services as rs', 'rrs.repair_service_id', '=', 'rs.id')
        ->join('repair_requests as rr', 'rrs.repair_request_id', '=', 'rr.id')
        ->where('rr.shop_owner_id', $so->id)
        ->whereIn('rr.status', ['completed','ready_for_pickup','picked_up'])
        ->where('rr.payment_status', 'completed')
        ->select('rs.name', Illuminate\Support\Facades\DB::raw('COUNT(DISTINCT rr.id) as cnt'), Illuminate\Support\Facades\DB::raw('SUM(rr.total) as rev'))
        ->groupBy('rs.id', 'rs.name', 'rs.price')
        ->orderByDesc('rev')
        ->limit(5)
        ->get();

    echo PHP_EOL . 'Top services:' . PHP_EOL;
    foreach ($services as $s) {
        echo '  ' . $s->name . ': ' . $s->cnt . ' jobs, rev=' . $s->rev . PHP_EOL;
    }

    // Test monthly trend
    $thisMonth = App\Models\RepairRequest::where('shop_owner_id', $so->id)
        ->whereIn('status', ['completed','ready_for_pickup','picked_up'])
        ->where('payment_status', 'completed')
        ->whereMonth('completed_at', now()->month)
        ->whereYear('completed_at', now()->year)
        ->sum('total');
    echo PHP_EOL . 'This month revenue: ' . $thisMonth . PHP_EOL;

    echo PHP_EOL . 'DSS controller data check: OK' . PHP_EOL;
} else {
    echo 'No shop owners in DB' . PHP_EOL;
}
