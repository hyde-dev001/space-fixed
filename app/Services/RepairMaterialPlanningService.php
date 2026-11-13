<?php

namespace App\Services;

use App\Models\RepairMaterialTemplateItem;
use App\Models\RepairPackage;
use App\Models\RepairRequest;
use App\Models\RepairService;
use Illuminate\Support\Facades\DB;

class RepairMaterialPlanningService
{
    /** Capture only at booking or an explicitly permitted service edit, never on a read. */
    public function snapshot(RepairRequest $repair, string $source = 'booking'): void
    {
        DB::transaction(function () use ($repair, $source): void {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            if ($source === 'booking' && $locked->material_plan_snapshot !== null) {
                $repair->setAttribute('material_plan_snapshot', $locked->material_plan_snapshot);
                $repair->unsetRelation('materialPlanItems');
                return;
            }
            if (app(RepairResolutionEligibilityService::class)->hasMaterialUsageHistory($locked)) {
                abort(409, 'Material usage has already been recorded; services can no longer be modified.');
            }

            $snapshot = $this->buildSnapshot($locked, $source);
            $locked->update(['material_plan_snapshot' => $snapshot]);
            $ids = array_column($snapshot['items'], 'inventory_item_id');
            $locked->materialPlanItems()->whereNotIn('inventory_item_id', $ids)->delete();
            foreach ($snapshot['items'] as $line) {
                $locked->materialPlanItems()->updateOrCreate(
                    ['inventory_item_id' => $line['inventory_item_id']],
                    $line + ['actual_quantity' => 0, 'variance_note' => null, 'variance_status' => 'within_tolerance'],
                );
            }
            $repair->setAttribute('material_plan_snapshot', $snapshot);
            $repair->unsetRelation('materialPlanItems');
        });
    }

    public function ensurePlan(RepairRequest $repair): void
    {
        DB::transaction(function () use ($repair): void {
            $locked = RepairRequest::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $snapshot = $locked->material_plan_snapshot;
            if ($snapshot === null) {
                if (in_array($locked->status, ['completed', 'ready_for_pickup', 'ready-for-pickup', 'shipped', 'picked_up', 'cancelled', 'rejected'], true)) {
                    // Read historical rows as stored; do not rewrite terminal jobs or infer missing requirements.
                    $repair->unsetRelation('materialPlanItems');
                    return;
                }
                $existing = $locked->materialPlanItems()->get();
                if ($existing->isNotEmpty()) {
                    $snapshot = [
                        'version' => 1, 'source' => 'legacy_plan',
                        'items' => $existing->map(fn ($line) => [
                            'inventory_item_id' => (int) $line->inventory_item_id,
                            'planned_quantity' => (float) $line->planned_quantity,
                            'is_critical' => (bool) $line->is_critical,
                            'tolerance_percent' => (float) $line->tolerance_percent,
                        ])->all(),
                    ];
                } elseif (app(RepairResolutionEligibilityService::class)->hasMaterialUsageHistory($locked)) {
                    // An absent historical plan is not evidence of booking-time requirements.
                    $snapshot = ['version' => 1, 'source' => 'legacy_no_plan', 'items' => []];
                } else {
                    $snapshot = $this->buildSnapshot($locked, 'legacy_templates');
                }
                $locked->update(['material_plan_snapshot' => $snapshot]);
            }

            foreach ($snapshot['items'] as $line) {
                $locked->materialPlanItems()->firstOrCreate(
                    ['inventory_item_id' => $line['inventory_item_id']],
                    $line + ['actual_quantity' => 0],
                );
            }
            $repair->setAttribute('material_plan_snapshot', $snapshot);
            $repair->unsetRelation('materialPlanItems');
        });
    }

    private function buildSnapshot(RepairRequest $repair, string $source): array
    {
        $package = RepairPackage::withTrashed()->where('shop_owner_id', $repair->shop_owner_id)
            ->find($repair->repair_package_id);
        $snapshotServiceIds = collect(array_merge(
            (array) $repair->included_services_snapshot,
            (array) $repair->add_on_services_snapshot,
        ))->map(fn ($row) => is_array($row)
            ? (int) ($row['id'] ?? $row['service_id'] ?? $row['repair_service_id'] ?? data_get($row, 'service.id') ?? 0)
            : 0);
        $serviceIds = $repair->services()->withTrashed()->pluck('repair_services.id')
            ->merge($package?->services()->withTrashed()->pluck('repair_services.id') ?? [])
            ->merge($snapshotServiceIds)->filter(fn ($id) => (int) $id > 0)->unique();
        $serviceIds = RepairService::withTrashed()->where('shop_owner_id', $repair->shop_owner_id)
            ->whereIn('id', $serviceIds)->orderBy('id')->pluck('id');
        $rows = RepairMaterialTemplateItem::query()
            ->where('shop_owner_id', $repair->shop_owner_id)
            ->whereHas('inventoryItem', fn ($query) => $query->where('shop_owner_id', $repair->shop_owner_id))
            ->where(function ($query) use ($package, $serviceIds): void {
                $query->where(fn ($services) => $services->where('template_type', 'repair_service')->whereIn('template_id', $serviceIds));
                if ($package) {
                    $query->orWhere(fn ($packages) => $packages->where('template_type', 'repair_package')->where('template_id', $package->id));
                }
            })->orderBy('id')->get();
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->inventory_item_id;
            $items[$id] ??= [
                'inventory_item_id' => $id, 'planned_quantity' => 0.0,
                'is_critical' => false, 'tolerance_percent' => 0.0,
            ];
            $items[$id]['planned_quantity'] += (float) $row->default_quantity;
            $items[$id]['is_critical'] = $items[$id]['is_critical'] || (bool) $row->is_critical;
            $items[$id]['tolerance_percent'] = max($items[$id]['tolerance_percent'], (float) ($row->tolerance_percent ?? 20));
        }
        foreach ($items as &$line) {
            $line['planned_quantity'] = round(max($line['planned_quantity'], 0), 2);
            $line['tolerance_percent'] = round(max($line['tolerance_percent'], 0), 2);
        }
        unset($line);

        return [
            'version' => 1, 'source' => $source, 'captured_at' => now()->toISOString(),
            'package_id' => $package?->id, 'service_ids' => $serviceIds->all(), 'template_ids' => $rows->modelKeys(),
            'items' => array_values(array_filter($items, fn ($line) => $line['planned_quantity'] > 0)),
        ];
    }

    public function validateStartReadiness(RepairRequest $repair): array
    {
        $blockers = [];

        foreach ($repair->materialPlanItems()->with('inventoryItem')->get() as $line) {
            $available = (float) ($line->inventoryItem->available_quantity ?? 0);
            $plannedQuantity = (float) $line->planned_quantity;

            if ($available < $plannedQuantity) {
                $blockers[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                    'needed' => $plannedQuantity,
                    'available' => $available,
                ];
            }
        }

        return [
            'readiness_state' => empty($blockers) ? 'ready' : 'blocked',
            'blockers' => $blockers,
            'warnings' => [],
            'actions' => ['request_materials'],
        ];
    }

    public function validateCompletionReadiness(RepairRequest $repair): array
    {
        $varianceIssues = [];

        foreach ($repair->materialPlanItems()->with('inventoryItem:id,name')->get() as $line) {
            $rawPlanned = max((float) $line->planned_quantity, 0.01);
            // Material usage logging accepts whole numbers only, so fractional plans
            // are evaluated against their whole-unit requirement.
            $planned = abs($rawPlanned - round($rawPlanned)) > 0.00001
                ? (float) ceil($rawPlanned)
                : $rawPlanned;
            $actual = (float) $line->actual_quantity;
            $variancePercent = abs($actual - $planned) / $planned * 100;
            $hasMaterialVariance = abs($actual - $planned) > 0.00001;

            if ($hasMaterialVariance && empty($line->variance_note)) {
                $varianceIssues[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                    'planned_quantity' => $rawPlanned,
                    'comparison_quantity' => $planned,
                    'actual_quantity' => $actual,
                    'variance_percent' => round($variancePercent, 2),
                ];
            }
        }

        return [
            'readiness_state' => empty($varianceIssues) ? 'ready' : 'variance_review_needed',
            'blockers' => [],
            'warnings' => $varianceIssues,
            'actions' => empty($varianceIssues) ? [] : ['add_variance_note_or_escalate'],
        ];
    }
}
