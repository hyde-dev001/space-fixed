<?php

namespace App\Console\Commands;

use App\Models\Logistics\DeliveryIncident;
use Illuminate\Console\Command;

class PreviewParcelIncidentRecovery extends Command
{
    protected $signature = 'logistics:preview-parcel-recovery {--shop= : Shop ID to inspect}';
    protected $description = 'Read-only, tenant-scoped preview of incomplete or ambiguous parcel recovery records';

    public function handle(): int
    {
        $shop = filter_var($this->option('shop'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $shop) {
            $this->error('Supply a positive --shop ID. This command never changes records.');
            return self::INVALID;
        }
        $incidents = DeliveryIncident::with(['leg.proofs', 'leg.custodyTransfers', 'leg.returnLeg'])
            ->where('shop_owner_id', $shop)->whereNotNull('shipment_leg_id')->orderBy('id')->get();
        $byId = $incidents->keyBy('id');
        $children = $incidents->filter(fn ($row) => data_get($row->recovery_context, 'recovered_loss.parent_id'))
            ->groupBy(fn ($row) => data_get($row->recovery_context, 'recovered_loss.parent_id'));
        $rows = [];
        foreach ($incidents as $incident) {
            $issues = [];
            $parentId = data_get($incident->recovery_context, 'recovered_loss.parent_id');
            if ($parentId) {
                $parent = $byId->get($parentId);
                if (! $parent || $parent->type !== 'lost' || $parent->shipment_leg_id !== $incident->shipment_leg_id) $issues[] = 'recovery_parent_requires_manual_review';
                if (! data_get($incident->recovery_context, 'recovered_loss.confirmed')) $issues[] = 'historical_recovery_confirmation_requires_manual_review';
                if ($children->get($parentId)->where('status', '!=', 'resolved')->count() > 1) $issues[] = 'multiple_active_recoveries_require_manual_review';
            }
            if ($incident->type === 'lost' && ! $incident->resolved_at && ! data_get($incident->recovery_context, 'loss.stage')) $issues[] = 'legacy_report_needs_actual_dispatcher_acknowledgement';
            if ($incident->resolution === 'return_required' && $incident->status !== 'resolved') $issues[] = 'return_receipt_or_item_handling_pending';
            if ($incident->leg?->custody_state === 'shop' && ! data_get($incident->recovery_context, 'receipt')
                && ! $incident->leg->proofs->contains(fn ($proof) => $proof->handoff_type === 'receive' && $proof->review_status === 'approved' && $proof->reviewed_by_type === \App\Models\User::class && $proof->reviewed_by_id)
                && ! $incident->leg->custodyTransfers->contains(fn ($transfer) => $transfer->status === 'received' && $transfer->to_type === 'shop' && $transfer->received_by_user_id)) {
                $issues[] = 'shop_custody_receiver_evidence_requires_manual_review';
            }
            if ($issues) $rows[] = ['incident_id' => $incident->id, 'parent_incident_id' => $parentId,
                'delivery_id' => $incident->shipment_leg_id, 'issues' => $issues,
                'next_actor' => in_array('return_receipt_or_item_handling_pending', $issues, true) ? 'staff_and_dispatcher' : 'dispatcher'];
        }
        $this->line(json_encode(['shop_id' => $shop, 'mode' => 'preview_only', 'records' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
