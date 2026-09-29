<?php

namespace App\Console\Commands;

use App\Enums\Logistics\RiderProgressState;
use App\Enums\Logistics\ShipmentLegStatus;
use App\Models\Logistics\DeliveryEvent;
use App\Models\Logistics\ShipmentLeg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillRiderProgressState extends Command
{
    protected $signature = 'logistics:backfill-rider-progress-state';

    protected $description = 'Backfill rider progress and proof-correction states for existing shipment legs';

    private array $counts = [
        'terminal_released' => 0,
        'rejected_correction_required' => 0,
        'awaiting_pending_submitted' => 0,
        'awaiting_approved_released' => 0,
        'awaiting_without_proof_active' => 0,
        'other_active' => 0,
        'reconciliation_markers' => 0,
    ];

    public function handle(): int
    {
        ShipmentLeg::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($legs): void {
                foreach ($legs as $leg) {
                    $this->backfillLeg($leg->id);
                }
            });

        foreach ($this->counts as $name => $count) {
            $this->line(ucfirst(str_replace('_', ' ', $name)).": {$count}");
        }

        return self::SUCCESS;
    }

    private function backfillLeg(int $legId): void
    {
        DB::transaction(function () use ($legId): void {
            $leg = ShipmentLeg::query()
                ->lockForUpdate()
                ->find($legId);

            if (!$leg) {
                return;
            }

            $latestDeliveryProof = $leg->proofs()
                ->where('handoff_type', 'delivery')
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();
            $status = $leg->status->value;

            if (in_array($status, [
                ShipmentLegStatus::DELIVERED->value,
                ShipmentLegStatus::CANCELLED->value,
                ShipmentLegStatus::FAILED->value,
            ], true)) {
                $leg->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                $this->counts['terminal_released']++;

                return;
            }

            if ($latestDeliveryProof?->review_status === 'rejected') {
                $leg->update([
                    'status' => ShipmentLegStatus::PROOF_CORRECTION_REQUIRED,
                    'rider_progress_state' => RiderProgressState::PROOF_ACTION_REQUIRED,
                ]);
                $this->counts['rejected_correction_required']++;

                return;
            }

            if ($status === ShipmentLegStatus::AWAITING_PROOF_APPROVAL->value) {
                if ($latestDeliveryProof?->review_status === 'pending') {
                    $leg->update(['rider_progress_state' => RiderProgressState::PROOF_SUBMITTED]);
                    $this->counts['awaiting_pending_submitted']++;

                    return;
                }

                if ($latestDeliveryProof?->review_status === 'approved') {
                    $leg->update(['rider_progress_state' => RiderProgressState::RIDER_RELEASED]);
                    $this->recordReconciliationMarker($leg, $latestDeliveryProof->id);
                    $this->counts['awaiting_approved_released']++;

                    return;
                }

                $leg->update(['rider_progress_state' => RiderProgressState::ACTIVE]);
                $this->counts['awaiting_without_proof_active']++;

                return;
            }

            $leg->update(['rider_progress_state' => RiderProgressState::ACTIVE]);
            $this->counts['other_active']++;
        });
    }

    private function recordReconciliationMarker(ShipmentLeg $leg, int $proofId): void
    {
        $event = DeliveryEvent::query()->firstOrCreate(
            [
                'shipment_id' => $leg->shipment_id,
                'shipment_leg_id' => $leg->id,
                'event_type' => 'proof_reconciliation_required',
                'message' => "Legacy approved delivery proof {$proofId} requires reconciliation.",
            ],
            [
                'visibility' => 'internal',
                'metadata' => [
                    'proof_id' => $proofId,
                    'source' => 'rider_progress_backfill',
                ],
            ]
        );

        if ($event->wasRecentlyCreated) {
            $this->counts['reconciliation_markers']++;
        }
    }
}
