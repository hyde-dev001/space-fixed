<?php

namespace App\Services\Manager;

use App\Models\InventoryItem;
use App\Models\ManagerReport;
use App\Models\Order;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagerReportService
{
    /**
     * @return array<string, array{title: string, description: string}>
     */
    public function definitions(): array
    {
        return [
            'sales' => [
                'title' => 'Sales Report',
                'description' => 'Order volume, canonical order totals, and product detail',
            ],
            'stock' => [
                'title' => 'Stock Update Report',
                'description' => 'Current inventory levels and stock health',
            ],
            'damaged' => [
                'title' => 'Damaged Items Report',
                'description' => 'Items marked as damaged or defective',
            ],
            'missing' => [
                'title' => 'Missing Items Report',
                'description' => 'Inventory movements recorded as missing or unaccounted',
            ],
            'performance' => [
                'title' => 'Performance Summary',
                'description' => 'Order volume and revenue by assigned staff member',
            ],
        ];
    }

    /**
     * @return array{start: mixed, end: mixed, label: string}
     */
    public function resolveRange(string $dateRange): array
    {
        $end = now()->endOfDay();

        $start = match ($dateRange) {
            'week' => now()->subDays(6)->startOfDay(),
            'month' => now()->subDays(29)->startOfDay(),
            'quarter' => now()->subMonths(3)->startOfDay(),
            'year' => now()->subYear()->startOfDay(),
            default => now()->subDays(6)->startOfDay(),
        };

        return [
            'start' => $start,
            'end' => $end,
            'label' => $dateRange,
        ];
    }

    /**
     * Return the Manager report listing and issue summary for one shop.
     */
    public function list(int $shopOwnerId): array
    {
        $snapshotAt = now();
        $recentReports = collect();
        $reportsGenerated = 0;
        $reportsReviewed = 0;

        if (Schema::hasTable('manager_reports')) {
            $recentReports = ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get();

            $monthStart = now()->startOfMonth();
            $reportsGenerated = (int) ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('generated_at', '>=', $monthStart)
                ->count();
            $reportsReviewed = (int) ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->whereNotNull('reviewed_at')
                ->where('reviewed_at', '>=', $monthStart)
                ->count();
        }

        $latestByType = $recentReports->groupBy('report_type')->map(
            fn (Collection $rows) => $rows->first(),
        );

        $reportTypes = collect($this->definitions())->map(
            function (array $config, string $id) use ($latestByType): array {
                $latest = $latestByType->get($id);

                return [
                    'id' => $id,
                    'title' => $config['title'],
                    'description' => $config['description'],
                    'last_report' => $latest instanceof ManagerReport
                        ? $this->map($latest)
                        : null,
                ];
            },
        )->values()->all();

        $lowStockCount = 0;
        $outOfStockCount = 0;

        if (Schema::hasTable('inventory_items')) {
            $inventory = InventoryItem::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true);
            $lowStockCount = (int) (clone $inventory)
                ->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level')
                ->count();
            $outOfStockCount = (int) (clone $inventory)
                ->where('available_quantity', '<=', 0)
                ->count();
        }

        return [
            'metrics' => [
                'reports_generated' => $reportsGenerated,
                'pending_issues' => $lowStockCount + $outOfStockCount,
                'reports_reviewed' => $reportsReviewed,
                'last_updated_at' => $snapshotAt->toISOString(),
            ],
            'report_types' => $reportTypes,
            'recent_reports' => $recentReports
                ->take(10)
                ->map(fn (ManagerReport $report): array => $this->map($report))
                ->values()
                ->all(),
        ];
    }

    public function generate(
        int $shopOwnerId,
        User $actor,
        string $reportType,
        string $dateRange,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): ManagerReport {
        if (! array_key_exists($reportType, $this->definitions())) {
            throw new ConflictHttpException('The selected report type is not available.');
        }

        if ($idempotencyKey !== null) {
            $existing = ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $range = $this->resolveRange($dateRange);
        $reportData = $this->buildData($shopOwnerId, $reportType, $range['start'], $range['end']);

        try {
            $result = DB::transaction(function () use (
                $shopOwnerId,
                $actor,
                $reportType,
                $dateRange,
                $notes,
                $idempotencyKey,
                $range,
                $reportData,
            ): array {
                if ($idempotencyKey !== null) {
                    $existing = ManagerReport::query()
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return ['report' => $existing, 'created' => false];
                    }
                }

                $report = ManagerReport::query()->create([
                    'shop_owner_id' => $shopOwnerId,
                    'report_type' => $reportType,
                    'date_range' => $dateRange,
                    'period_start' => $range['start'],
                    'period_end' => $range['end'],
                    'status' => 'generated',
                    'notes' => $notes,
                    'report_data' => $reportData,
                    'generated_by' => $actor->id,
                    'generated_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                return ['report' => $report, 'created' => true];
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = ManagerReport::query()
                    ->where('shop_owner_id', $shopOwnerId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $exception;
        }

        /** @var ManagerReport $report */
        $report = $result['report'];

        if ($result['created'] === true) {
            $this->storeFile($report);
            $this->auditReport(
                report: $report,
                actor: $actor,
                action: 'report_generated',
                previousState: [],
                newState: ['status' => 'generated'],
                reason: $notes,
            );
        }

        return $report->fresh() ?? $report;
    }

    public function review(int $shopOwnerId, int $reportId, User $actor, string $notes): ManagerReport
    {
        return DB::transaction(function () use ($shopOwnerId, $reportId, $actor, $notes): ManagerReport {
            $report = ManagerReport::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->findOrFail($reportId);

            // Repeated review requests return the already committed result and
            // do not overwrite the original decision or audit fields.
            if (in_array((string) $report->status, ['reviewed', 'sent'], true)) {
                return $report;
            }

            if ((string) $report->status !== 'generated') {
                throw new ConflictHttpException('Only a generated report can be reviewed.');
            }

            $previousStatus = (string) $report->status;
            $report->forceFill([
                'status' => 'reviewed',
                'notes' => $notes,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            $this->auditReport(
                report: $report,
                actor: $actor,
                action: 'report_reviewed',
                previousState: ['status' => $previousStatus],
                newState: ['status' => 'reviewed'],
                reason: $notes,
            );

            return $report->fresh() ?? $report;
        });
    }

    /** @param array<string, mixed> $previousState @param array<string, mixed> $newState */
    private function auditReport(
        ManagerReport $report,
        User $actor,
        string $action,
        array $previousState,
        array $newState,
        ?string $reason,
    ): void {
        AuditLog::create([
            'shop_owner_id' => (int) $report->shop_owner_id,
            'user_id' => (int) $actor->id,
            'actor_user_id' => (int) $actor->id,
            'action' => $action,
            'object_type' => 'manager_report',
            'object_id' => (int) $report->id,
            'target_type' => 'manager_report',
            'target_id' => (int) $report->id,
            'metadata' => [
                'previous_state' => $previousState,
                'new_state' => $newState,
                'reason' => $reason,
                'reference_id' => 'manager-report:' . $report->id,
                'report_type' => $report->report_type,
                'date_range' => $report->date_range,
            ],
        ]);
    }

    public function reportForDownload(int $shopOwnerId, int $reportId): ManagerReport
    {
        $report = ManagerReport::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('status', ['generated', 'reviewed', 'sent'])
            ->findOrFail($reportId);

        $path = (string) ($report->file_path ?? '');
        $unsafePath = $path !== '' && (! str_starts_with($path, "manager-reports/{$shopOwnerId}/")
            || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', $path) || ! str_ends_with($path, '.csv'));
        if (! is_array(data_get($report->report_data, 'summary'))
            || ! is_array(data_get($report->report_data, 'rows')) || $unsafePath) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(ManagerReport::class, [$reportId]);
        }

        if (! $report->file_path) {
            $this->storeFile($report);
            $report->refresh();
        }

        // Export the stored snapshot, preserving the original artifact and its path.
        Storage::disk('local')->put($this->formattedFilePath($report), $this->buildCsvContent($report));
        $report->update(['downloaded_at' => now()]);

        return $report->fresh() ?? $report;
    }

    public function formattedFilePath(ManagerReport $report): string
    {
        return "manager-reports/{$report->shop_owner_id}/report-{$report->id}-formatted-v1.csv";
    }

    public function downloadFileName(ManagerReport $report): string
    {
        return sprintf(
            '%s-%s.csv',
            $report->report_type,
            optional($report->generated_at)->format('Ymd_His') ?? now()->format('Ymd_His'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function map(ManagerReport $report): array
    {
        $definitions = $this->definitions();
        $config = $definitions[$report->report_type] ?? [
            'title' => Str::headline($report->report_type),
            'description' => '',
        ];
        $status = (string) $report->status;

        // `sent` is a legacy persisted value. It is presented as reviewed
        // because this Manager workflow has no delivery operation.
        if ($status === 'sent') {
            $status = 'reviewed';
        }

        return [
            'id' => $report->id,
            'report_type' => $report->report_type,
            'report_title' => $config['title'],
            'description' => $config['description'],
            'date_range' => $report->date_range,
            'status' => $status,
            'notes' => $report->notes,
            'generated_at' => optional($report->generated_at)->toDateTimeString(),
            'reviewed_at' => optional($report->reviewed_at)->toDateTimeString(),
            'downloaded_at' => optional($report->downloaded_at)->toDateTimeString(),
            'period_start' => optional($report->period_start)->toDateTimeString(),
            'period_end' => optional($report->period_end)->toDateTimeString(),
            'summary' => $report->report_data['summary'] ?? [],
            'row_count' => count($report->report_data['rows'] ?? []),
        ];
    }

    private function buildData(int $shopOwnerId, string $reportType, $start, $end): array
    {
        return match ($reportType) {
            'sales' => $this->buildSalesData($shopOwnerId, $start, $end),
            'stock' => $this->buildStockData($shopOwnerId),
            'damaged' => $this->buildDamagedData($shopOwnerId, $start, $end),
            'missing' => $this->buildMissingData($shopOwnerId, $start, $end),
            'performance' => $this->buildPerformanceData($shopOwnerId, $start, $end),
            default => ['summary' => [], 'rows' => []],
        };
    }

    private function buildSalesData(int $shopOwnerId, $start, $end): array
    {
        $orders = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end])
            ->with(['items' => fn ($query) => $query->select([
                'id',
                'order_id',
                'product_name',
                'quantity',
                'subtotal',
            ])])
            ->orderByDesc('created_at')
            ->get([
                'id',
                'order_number',
                'customer_name',
                'status',
                'total_amount',
                'assigned_staff_id',
                'created_at',
            ]);

        $totalRevenue = (float) $orders->sum(fn (Order $order): float => (float) $order->total_amount);
        $totalOrders = $orders->count();
        $completedOrders = $orders->filter(function (Order $order): bool {
            return in_array($this->valueOf($order->status), ['completed', 'delivered'], true);
        })->count();

        $staff = User::query()->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $orders->pluck('assigned_staff_id')->filter()->unique())
            ->pluck('name', 'id');
        $rows = $orders->map(function (Order $order) use ($staff): array {
            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => (string) ($order->customer_name ?? ''),
                'assigned_staff_id' => $order->assigned_staff_id,
                'assigned_staff_name' => $order->assigned_staff_id
                    ? ($staff->get($order->assigned_staff_id) ?? 'Unknown staff') : 'Unassigned',
                'status' => $this->valueOf($order->status),
                'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
                'order_items' => $order->items->map(fn ($item): array => [
                    'product_name' => (string) $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'subtotal' => number_format((float) $item->subtotal, 2, '.', ''),
                ])->values()->all(),
                'created_at' => optional($order->created_at)->toDateTimeString(),
            ];
        })->values()->all();

        return [
            'summary' => [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                'average_order_value' => $totalOrders > 0
                    ? number_format($totalRevenue / $totalOrders, 2, '.', '')
                    : '0.00',
            ],
            'rows' => $rows,
        ];
    }

    private function buildStockData(int $shopOwnerId): array
    {
        $items = InventoryItem::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category', 'available_quantity', 'reorder_level', 'price', 'updated_at']);

        $rows = $items->map(function (InventoryItem $item): array {
            $stockStatus = $item->available_quantity <= 0
                ? 'Out of Stock'
                : ($item->available_quantity <= $item->reorder_level ? 'Low Stock' : 'In Stock');

            return [
                'item_id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category,
                'available_quantity' => $item->available_quantity,
                'reorder_level' => $item->reorder_level,
                'price' => number_format((float) ($item->price ?? 0), 2, '.', ''),
                'stock_status' => $stockStatus,
                'updated_at' => optional($item->updated_at)->toDateTimeString(),
            ];
        })->values()->all();

        return [
            'summary' => [
                'total_items' => $items->count(),
                'total_quantity' => (int) $items->sum('available_quantity'),
                'low_stock_count' => (int) $items->filter(fn (InventoryItem $item): bool => $item->available_quantity > 0 && $item->available_quantity <= $item->reorder_level)->count(),
                'out_of_stock_count' => (int) $items->filter(fn (InventoryItem $item): bool => $item->available_quantity <= 0)->count(),
            ],
            'rows' => $rows,
        ];
    }

    private function buildDamagedData(int $shopOwnerId, $start, $end): array
    {
        $damages = DB::table('stock_movements as sm')
            ->join('inventory_items as ii', 'sm.inventory_item_id', '=', 'ii.id')
            ->where('ii.shop_owner_id', $shopOwnerId)
            ->where('sm.movement_type', 'damage')
            ->whereBetween('sm.performed_at', [$start, $end])
            ->select(['sm.id', 'ii.name', 'ii.sku', 'sm.quantity_change', 'sm.notes', 'sm.performed_at'])
            ->orderByDesc('sm.performed_at')
            ->get();

        return [
            'summary' => [
                'damage_incidents' => $damages->count(),
                'total_units_damaged' => (int) $damages->sum(fn ($damage): int => abs((int) ($damage->quantity_change ?? 0))),
            ],
            'rows' => $damages->map(fn ($damage): array => [
                'movement_id' => $damage->id,
                'item_name' => (string) ($damage->name ?? ''),
                'sku' => (string) ($damage->sku ?? ''),
                'quantity_damaged' => abs((int) ($damage->quantity_change ?? 0)),
                'notes' => (string) ($damage->notes ?? ''),
                'recorded_at' => (string) ($damage->performed_at ?? ''),
            ])->values()->all(),
        ];
    }

    private function buildMissingData(int $shopOwnerId, $start, $end): array
    {
        $missingRows = DB::table('stock_movements as sm')
            ->join('inventory_items as ii', 'sm.inventory_item_id', '=', 'ii.id')
            ->where('ii.shop_owner_id', $shopOwnerId)
            ->whereBetween('sm.performed_at', [$start, $end])
            ->where(function ($query): void {
                $query
                    ->where(function ($nested): void {
                        $nested->where('sm.movement_type', 'adjustment')
                            ->where('sm.quantity_change', '<', 0)
                            ->where(function ($notes): void {
                                $notes->where('sm.notes', 'like', '%missing%')
                                    ->orWhere('sm.notes', 'like', '%lost%')
                                    ->orWhere('sm.notes', 'like', '%shrink%')
                                    ->orWhere('sm.notes', 'like', '%unaccounted%');
                            });
                    })
                    ->orWhere('sm.reference_type', 'missing');
            })
            ->select(['sm.id', 'ii.name', 'ii.sku', 'sm.quantity_change', 'sm.notes', 'sm.performed_at'])
            ->orderByDesc('sm.performed_at')
            ->get();

        return [
            'summary' => [
                'missing_incidents' => $missingRows->count(),
                'total_units_missing' => (int) $missingRows->sum(fn ($missing): int => abs((int) ($missing->quantity_change ?? 0))),
            ],
            'rows' => $missingRows->map(fn ($missing): array => [
                'movement_id' => $missing->id,
                'item_name' => (string) ($missing->name ?? ''),
                'sku' => (string) ($missing->sku ?? ''),
                'quantity_missing' => abs((int) ($missing->quantity_change ?? 0)),
                'notes' => (string) ($missing->notes ?? ''),
                'recorded_at' => (string) ($missing->performed_at ?? ''),
            ])->values()->all(),
        ];
    }

    private function buildPerformanceData(int $shopOwnerId, $start, $end): array
    {
        $orders = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereBetween('created_at', [$start, $end])
            ->get(['assigned_staff_id', 'status', 'total_amount']);

        $staffIds = $orders->pluck('assigned_staff_id')->filter()->unique()->values();
        $staff = User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $staffIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $grouped = $orders->groupBy(fn (Order $order): string => (string) ($order->assigned_staff_id ?? 0));
        $rows = $grouped->map(function (Collection $staffOrders, string $staffId) use ($staff): array {
            $numericStaffId = (int) $staffId;
            $revenue = (float) $staffOrders->sum(fn (Order $order): float => (float) $order->total_amount);

            return [
                'staff_id' => $numericStaffId > 0 ? $numericStaffId : null,
                'staff_name' => $numericStaffId > 0
                    ? (string) ($staff->get($numericStaffId)?->name ?? 'Unknown staff')
                    : 'Unassigned',
                'order_count' => $staffOrders->count(),
                'completed_orders' => $staffOrders->filter(
                    fn (Order $order): bool => in_array($this->valueOf($order->status), ['completed', 'delivered'], true),
                )->count(),
                'total_revenue' => number_format($revenue, 2, '.', ''),
                'average_order_value' => $staffOrders->count() > 0
                    ? number_format($revenue / $staffOrders->count(), 2, '.', '')
                    : '0.00',
            ];
        })->values()->all();

        return [
            'summary' => [
                'staff_count' => count($rows),
                'order_count' => $orders->count(),
                'completed_orders' => $orders->filter(
                    fn (Order $order): bool => in_array($this->valueOf($order->status), ['completed', 'delivered'], true),
                )->count(),
                'total_revenue' => number_format((float) $orders->sum(fn (Order $order): float => (float) $order->total_amount), 2, '.', ''),
            ],
            'rows' => $rows,
        ];
    }

    private function storeFile(ManagerReport $report): void
    {
        $content = $this->buildCsvContent($report);
        $path = "manager-reports/{$report->shop_owner_id}/report-{$report->id}.csv";

        Storage::disk('local')->put($path, $content);

        if ($report->file_path !== $path) {
            $report->update(['file_path' => $path]);
        }
    }

    private function buildCsvContent(ManagerReport $report): string
    {
        $summary = $report->report_data['summary'] ?? [];
        $rows = $report->report_data['rows'] ?? [];
        $stream = fopen('php://temp', 'w+');
        // Let spreadsheet applications detect UTF-8 when opening the CSV directly.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Summary']);

        foreach ($summary as $key => $value) {
            fputcsv($stream, [$this->safeCsvText(Str::headline($key)), $this->csvCell($key, $value)]);
        }

        fputcsv($stream, []);
        fputcsv($stream, ['Details']);

        if ($rows === []) {
            fputcsv($stream, ['No records found for selected date range']);
        } else {
            $sales = $report->report_type === 'sales';
            $headers = $sales ? [
                'order_number' => 'Order Number', 'customer_name' => 'Customer',
                'assigned_staff_name' => 'Assigned Staff', 'status' => 'Status',
                'total_amount' => 'Total Amount', 'order_items' => 'Order Items', 'created_at' => 'Created At',
            ] : collect($rows)->flatMap(fn ($row) => array_keys((array) $row))->unique()
                ->reject(fn ($key) => $key === 'id' || str_ends_with($key, '_id'))
                ->mapWithKeys(fn ($key) => [$key => $this->safeCsvText(Str::headline($key))])->all();
            // Legacy snapshots may only have an assignee ID; resolve names once, within this tenant.
            $staff = $sales ? User::query()->where('shop_owner_id', $report->shop_owner_id)
                ->whereIn('id', collect($rows)->filter(fn ($row) => empty($row['assigned_staff_name']))
                    ->pluck('assigned_staff_id')->filter()->unique())->pluck('name', 'id') : collect();
            fputcsv($stream, array_values($headers));

            foreach ($rows as $row) {
                $row = (array) $row;
                if ($sales && empty($row['assigned_staff_name'])) {
                    $row['assigned_staff_name'] = ! empty($row['assigned_staff_id'])
                        ? ($staff->get($row['assigned_staff_id']) ?? 'Unknown staff') : 'Unassigned';
                }
                $cells = [];
                foreach ($headers as $key => $label) {
                    $value = $row[$key] ?? null;
                    if ($key === 'order_items') {
                        $value = $this->formatOrderItems($value);
                    } elseif ($key === 'status' && is_string($value)) {
                        $value = Str::headline($value);
                    }
                    $cells[] = $this->csvCell($key, $value);
                }
                fputcsv($stream, $cells);
            }
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content ?: '';
    }

    private function formatOrderItems(mixed $items): string
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (! is_array($decoded)) {
                return $items;
            }
            $items = $decoded;
        }
        if (! is_array($items)) {
            return '';
        }
        if (isset($items['product_name']) || isset($items['name'])) {
            $items = [$items];
        }
        $formatted = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $formatted[] = $this->readableValue($item);
                continue;
            }
            $name = $item['product_name'] ?? $item['name'] ?? '';
            $quantity = $item['quantity'] ?? $item['qty'] ?? null;
            $subtotal = $item['subtotal'] ?? $item['total'] ?? null;
            $description = $this->readableValue($name);
            if (is_numeric($quantity)) {
                $description .= ' × '.(string) $quantity;
            }
            if (is_numeric($subtotal)) {
                $description .= ' — ₱'.number_format((float) $subtotal, 2);
            }
            // Also protect a later item, rather than only the first item in the cell.
            $formatted[] = $this->safeCsvText($description);
        }
        return implode('; ', $formatted);
    }

    private function csvCell(string $key, mixed $value): string
    {
        $numericFields = ['total_amount', 'total_revenue', 'average_order_value', 'price', 'subtotal',
            'quantity', 'available_quantity', 'reorder_level', 'total_quantity', 'total_orders',
            'completed_orders', 'total_items', 'low_stock_count', 'out_of_stock_count', 'staff_count',
            'order_count', 'damaged_count', 'missing_count'];
        if (is_numeric($value) && in_array($key, $numericFields, true)) {
            return (string) $value;
        }
        return $this->safeCsvText($this->readableValue($value));
    }

    private function readableValue(mixed $value): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $parts = [];
        foreach ($value as $key => $nested) {
            $parts[] = (is_string($key) ? Str::headline($key).': ' : '').$this->readableValue($nested);
        }
        return implode('; ', $parts);
    }

    private function safeCsvText(string $value): string
    {
        return preg_match('/^[\s\x00-\x20\x{FEFF}]*[=+\-@]/u', $value) ? "'".$value : $value;
    }

    private function valueOf(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
