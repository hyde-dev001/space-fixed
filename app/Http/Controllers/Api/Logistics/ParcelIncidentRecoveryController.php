<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryIncident;
use App\Models\User;
use App\Services\Logistics\LogisticsActorPolicy;
use App\Services\Logistics\ParcelIncidentRecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ParcelIncidentRecoveryController extends Controller
{
    public function evidence(DeliveryIncident $incident, string $token, LogisticsActorPolicy $policy, ParcelIncidentRecoveryService $service)
    {
        $this->actor($incident, $policy);
        $path = collect($service->evidencePaths($incident))->first(fn ($candidate) => hash_equals(hash('sha256', $candidate), $token));
        abort_unless(is_string($path) && (str_starts_with($path, 'incident-evidence/') || str_starts_with($path, 'logistics-proof/'))
            && ! str_contains($path, '..') && ! str_contains($path, '\\') && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function index(ParcelIncidentRecoveryService $service)
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        return response()->json(['recoveries' => $service->pendingFor($actor)]);
    }

    public function receive(Request $request, DeliveryIncident $incident, ParcelIncidentRecoveryService $service, LogisticsActorPolicy $policy)
    {
        $actor = $this->actor($incident, $policy);
        $data = $request->validate([...$this->actionRules(), 'proof_id' => ['nullable', 'integer'],
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        return $this->withEvidence($request, $incident, $data, fn ($facts) => $service->receive($incident, $actor, $facts));
    }

    public function inspect(Request $request, DeliveryIncident $incident, ParcelIncidentRecoveryService $service, LogisticsActorPolicy $policy)
    {
        $actor = $this->actor($incident, $policy);
        $data = $request->validate([...$this->actionRules(), 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array:reference,quantity,condition'], 'items.*.reference' => ['required', 'string', 'max:150', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0'], 'items.*.condition' => ['required', 'in:safe,damaged,missing,unsafe,unidentified'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        return $this->withEvidence($request, $incident, $data, fn ($facts) => $service->inspect($incident, $actor, $facts));
    }

    private function actor(DeliveryIncident $incident, LogisticsActorPolicy $policy): User
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User && $incident->shipment_leg_id && $policy->decideParcelRecovery($actor, $incident->leg)['allowed'], 403);
        return $actor;
    }

    public function close(Request $request, DeliveryIncident $incident, ParcelIncidentRecoveryService $service, LogisticsActorPolicy $policy)
    {
        $actor = $this->actor($incident, $policy);
        $data = $request->validate([...$this->actionRules(), 'outcome' => ['required', 'in:confirmed_loss,delivery_completed,shop_received,safekeeping_received']]);
        return response()->json(['recovery' => $service->close($incident, $actor, $data)]);
    }

    public function investigate(Request $request, DeliveryIncident $incident, ParcelIncidentRecoveryService $service, LogisticsActorPolicy $policy)
    {
        $actor = $this->actor($incident, $policy);
        $data = $request->validate([...$this->actionRules(), 'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'recovered_rider_profile_id' => ['nullable', 'integer'], 'safe_for_transport' => ['sometimes', 'boolean']]);
        return $this->withEvidence($request, $incident, $data, fn ($facts) => $service->investigate($incident, $actor, $facts));
    }

    public function disposition(Request $request, DeliveryIncident $incident, ParcelIncidentRecoveryService $service, LogisticsActorPolicy $policy)
    {
        $actor = $this->actor($incident, $policy);
        $data = $request->validate([...$this->actionRules(), 'action' => ['required', 'in:verify_readiness,redeliver_original,ready_for_retry,authorize_retry_handoff,refund_review,repair_recovery,escalate,inventory_disposition'],
            'findings' => ['sometimes', 'array:condition_verified,safe_for_delivery,recovered_verified,obstacle_resolved,dispute_resolved,operational_ready,customer_agreement,mitigation_plan'],
            'findings.condition_verified' => ['sometimes', 'boolean'], 'findings.safe_for_delivery' => ['sometimes', 'boolean'],
            'findings.recovered_verified' => ['sometimes', 'boolean'], 'findings.obstacle_resolved' => ['sometimes', 'boolean'],
            'findings.dispute_resolved' => ['sometimes', 'boolean'], 'findings.operational_ready' => ['sometimes', 'boolean'],
            'findings.customer_agreement' => ['sometimes', 'string', 'max:1000'],
            'findings.mitigation_plan' => ['sometimes', 'string', 'max:1000'],
            'delivery_date' => ['required_if:action,redeliver_original', 'date_format:Y-m-d'],
            'delivery_window' => ['required_if:action,redeliver_original', 'in:morning,afternoon'],
            'rider_profile_id' => ['required_if:action,redeliver_original', 'integer'],
            ...\App\Http\Requests\Logistics\ResolveDeliveryRetryRequest::immediateRules(),
            'appointment_confirmed' => ['sometimes', 'boolean'], 'appointment_agreement_note' => ['sometimes', 'string', 'max:1000']]);
        foreach ($data['findings'] ?? [] as $field => $value) {
            if (! in_array($field, ['customer_agreement', 'mitigation_plan'], true)) $data['findings'][$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return response()->json(['recovery' => $service->disposition($incident, $actor, $data)]);
    }

    private function actionRules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'], 'notes' => ['required', 'string', 'max:1000']];
    }

    private function withEvidence(Request $request, DeliveryIncident $incident, array $data, callable $operation)
    {
        unset($data['photo']);
        $path = null;
        if ($file = $request->file('photo')) {
            $path = $file->store('incident-evidence/recovery-'.$incident->id, 'local');
            if (! is_string($path) || $path === '') abort(500);
            $data['evidence_path'] = $path;
            $data['evidence_digest'] = hash_file('sha256', $file->getRealPath());
        }
        try {
            return response()->json(['recovery' => $operation($data)]);
        } finally {
            if ($path && ! $incident->leg->events()->where('metadata->evidence_path', $path)->exists()) Storage::disk('local')->delete($path);
        }
    }
}
