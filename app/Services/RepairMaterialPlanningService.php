<?php

namespace App\Services;

use App\Models\RepairRequest;

class RepairMaterialPlanningService
{
    public function validateStartReadiness(RepairRequest $repair): array
    {
        $blockers = [];
        $warnings = [];

        foreach ($repair->materialPlanItems()->with('inventoryItem')->get() as $line) {
            $available = (float) ($line->inventoryItem->available_quantity ?? 0);
            $plannedQuantity = (float) $line->planned_quantity;

            if ($line->is_critical && $available < $plannedQuantity) {
                $blockers[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                    'needed' => $plannedQuantity,
                    'available' => $available,
                ];
            }

            if (!$line->is_critical && $available < $plannedQuantity) {
                $warnings[] = [
                    'inventory_item_id' => $line->inventory_item_id,
                    'name' => $line->inventoryItem->name ?? 'Unknown',
                    'needed' => $plannedQuantity,
                    'available' => $available,
                ];
            }
        }

        return [
            'readiness_state' => empty($blockers) ? (empty($warnings) ? 'ready' : 'at_risk') : 'blocked',
            'blockers' => $blockers,
            'warnings' => $warnings,
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

            if ($variancePercent > (float) $line->tolerance_percent && empty($line->variance_note)) {
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
