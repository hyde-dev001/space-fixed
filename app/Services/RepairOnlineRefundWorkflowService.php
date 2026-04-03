<?php

namespace App\Services;

use App\Models\PosRefund;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RepairOnlineRefundWorkflowService
{
    public function repairerApprove(PosRefund $refund, int $actorId, string $assessmentNote, float $approvedAmount): PosRefund
    {
        if ((string) $refund->repairer_status !== 'pending') {
            throw ValidationException::withMessages([
                'repairer_status' => ['Repairer review already completed.'],
            ]);
        }

        $refund->update([
            'repairer_status' => 'approved',
            'repairer_assessment_note' => Str::limit(trim($assessmentNote), 2000, ''),
            'repairer_reviewed_by' => $actorId,
            'repairer_reviewed_at' => now(),
            'approved_amount' => round($approvedAmount, 2),
            'status' => 'requested',
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        return $refund->fresh();
    }

    public function repairerReject(PosRefund $refund, int $actorId, string $assessmentNote, string $reason): PosRefund
    {
        if ((string) $refund->repairer_status !== 'pending') {
            throw ValidationException::withMessages([
                'repairer_status' => ['Repairer review already completed.'],
            ]);
        }

        $refund->update([
            'repairer_status' => 'rejected',
            'repairer_assessment_note' => Str::limit(trim($assessmentNote), 2000, ''),
            'repairer_reviewed_by' => $actorId,
            'repairer_reviewed_at' => now(),
            'status' => 'rejected',
            'failure_reason' => Str::limit(trim($reason), 255, ''),
            'failed_at' => now(),
        ]);

        return $refund->fresh();
    }
}
