<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Enums\Logistics\LogisticsAction;
use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use App\Services\Logistics\LogisticsActorPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DeliveryIncidentController extends Controller
{
    public function __construct(private LogisticsActorPolicy $policy) {}

    public function storeBatch(\App\Http\Requests\Logistics\ReportBatchInterruptionRequest $request,
        \App\Models\Logistics\DeliveryBatch $batch, \App\Services\Logistics\BatchInterruptionService $service): JsonResponse
    {
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User && (int) $actor->shop_owner_id === (int) $batch->shop_owner_id, 403);
        $paths = $this->storeEvidenceFiles($request->file('photo_files', []), "incident-evidence/batch-{$batch->id}");
        try {
            $incident = $service->report($batch, $actor, [...$request->validated(), 'photo_paths' => $paths]);
            $this->deleteUncommittedEvidence($paths, $incident->photo_paths ?? []);
            return response()->json(['incident' => $this->incidentPayload($incident)], 201);
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($paths, []);
            throw $exception;
        }
    }

    public function store(Request $request, ShipmentLeg $leg, DeliveryIncidentService $service): JsonResponse
    {
        $leg->loadMissing('shipment');
        $actor = $this->authenticatedActor(false);
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);
        $decision = $this->policy->decideCustody($actor, $shop, $leg);
        if (! $decision['allowed']) {
            $this->logDenial($actor, $shop, $decision['action'], $decision['reason_category']);
            abort(403);
        }
        $rider = $this->policy->resolveAssignedRider($actor, $shop, $leg);
        abort_unless($rider, 403);
        $data = $request->validate([
            'type' => ['required', 'in:damaged,lost,vehicle_problem,customer_dispute,other'],
            'notes' => ['required', 'string', 'max:1000'],
            'photo_paths' => ['prohibited'],
            'photo_files' => [Rule::requiredIf($request->input('type') !== 'lost'), 'array', 'min:1', 'max:5'],
            'photo_files.*' => $this->evidenceFileRules(),
        ]);
        $storedPaths = $this->storeEvidenceFiles($request->file('photo_files', []), "incident-evidence/leg-{$leg->id}");

        try {
            $incident = $service->report($leg, $rider, [
                ...$data,
                'photo_paths' => $storedPaths,
            ]);
            $this->deleteUncommittedEvidence($storedPaths, $incident->photo_paths ?? []);

            return response()->json(['incident' => $this->incidentPayload($incident)], 201);
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($storedPaths, []);
            throw $exception;
        }
    }

    public function recovery(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
        abort_if(Auth::guard('shop_owner')->check(), 403);
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        app(LogisticsActorPolicy::class)->assertLossActor($actor, $incident, true);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000'], 'custody_rider_profile_id' => ['required', 'integer'],
            'condition' => ['required', 'in:safe,damaged,unsafe,partial'], 'photo_paths' => ['prohibited'],
            'photo_files' => ['required', 'array', 'min:1', 'max:5'], 'photo_files.*' => $this->evidenceFileRules()]);
        $paths = $this->storeEvidenceFiles($request->file('photo_files', []), "incident-evidence/recovery-{$incident->id}");
        try {
            $recovery = $service->reportRecovery($incident, $actor, [...$data, 'photo_paths' => $paths,
                'evidence_digests' => array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $request->file('photo_files'))]);
            $this->deleteUncommittedEvidence($paths, $recovery->evidencePaths());
            return response()->json(['incident' => $this->incidentPayload($recovery)], 201);
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($paths, []);
            throw $exception;
        }
    }

    public function reviewRecovery(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
        abort_if(Auth::guard('shop_owner')->check(), 403);
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000'], 'action' => ['required', 'in:return,continue'],
            'safe_for_transport' => ['required', 'accepted'], 'delivery_date' => ['required', 'date_format:Y-m-d'],
            'delivery_window' => ['required', 'in:morning,afternoon'], 'customer_agreement' => ['required_if:action,continue', 'string', 'max:1000'],
            'appointment_confirmed' => ['sometimes', 'boolean'], 'appointment_agreement_note' => ['sometimes', 'string', 'max:1000']]);
        return response()->json(['incident' => $this->incidentPayload($service->reviewRecovery($incident, $actor, $data))]);
    }

    public function loss(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
        abort_if(Auth::guard('shop_owner')->check(), 403);
        $actor = Auth::guard('user')->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate(['action' => ['required', 'in:acknowledge,confirm,approve'],
            'request_key' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:0'],
            'notes' => ['required', 'string', 'max:1000']]);
        return response()->json(['incident' => $this->incidentPayload($service->lossAction($incident, $actor, $data['action'], $data))]);
    }

    public function resolve(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
        if ($incident->delivery_batch_id) {
            $actor = Auth::guard('user')->user();
            abort_unless($actor instanceof User && $actor->can('resolve-logistics-exceptions'), 403);
            $data = $request->validate(\App\Http\Requests\Logistics\ResolveBatchInterruptionRequest::decisionRules());
            $resolved = app(\App\Services\Logistics\BatchInterruptionService::class)->resolve($incident, $actor, $data);
            return response()->json(['incident' => $this->incidentPayload($resolved)]);
        }
        $incident->loadMissing('leg.shipment');
        $actor = $this->authenticatedActor();
        $shop = $this->shopForActor($actor);
        abort_unless($shop, 403);
        if (! $incident->leg?->shipment || (int) $incident->shop_owner_id !== (int) $shop->id) {
            $this->logDenial($actor, $shop, LogisticsAction::RESOLVE_EXCEPTION->value, 'cross_shop');
            abort(403);
        }
        $decision = $this->policy->decide($actor, LogisticsAction::RESOLVE_EXCEPTION, $shop, $incident->leg);
        if (! $decision['allowed']) {
            $this->logDenial($actor, $shop, $decision['action'], $decision['reason_category']);
            abort(403);
        }
        $data = $request->validate([
            'resolution' => ['required', 'string', Rule::in(DeliveryIncidentService::RESOLUTIONS)],
            'request_key' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'findings' => ['required', 'array:condition_verified,safe_for_delivery,safe_for_transport,recovered_verified,obstacle_resolved,dispute_resolved,operational_ready,items,customer_agreement,mitigation_plan'],
            'findings.condition_verified' => ['sometimes', 'boolean'],
            'findings.safe_for_delivery' => ['sometimes', 'boolean'],
            'findings.safe_for_transport' => ['sometimes', 'boolean'],
            'findings.recovered_verified' => ['sometimes', 'boolean'],
            'findings.obstacle_resolved' => ['sometimes', 'boolean'],
            'findings.dispute_resolved' => ['sometimes', 'boolean'],
            'findings.operational_ready' => ['sometimes', 'boolean'],
            'findings.items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'findings.items.*' => ['array:reference,quantity,condition'],
            'findings.items.*.reference' => ['required', 'string', 'max:150'],
            'findings.items.*.quantity' => ['required', 'integer', 'min:1'],
            'findings.items.*.condition' => ['required', 'string', 'max:500'],
            'findings.customer_agreement' => ['sometimes', 'string', 'max:1000'],
            'findings.mitigation_plan' => ['sometimes', 'string', 'max:1000'],
            'note' => ['required', 'string', 'max:1000'],
            'responsible_party' => ['nullable', 'in:undetermined,customer,rider,shop,third_party'],
            'evidence_files' => [
                Rule::requiredIf($request->input('resolution') === 'loss_confirmed'),
                'nullable',
                'array',
                'min:1',
                'max:5',
            ],
            'evidence_files.*' => $this->evidenceFileRules(),
        ]);
        $retryOptions = $data['resolution'] === 'retry'
            ? $request->validate(\App\Http\Requests\Logistics\ResolveDeliveryRetryRequest::retryRules()) : [];
        $storedPaths = $this->storeEvidenceFiles($request->file('evidence_files', []), "incident-evidence/incident-{$incident->id}");

        try {
            $findings = $data['findings'];
            foreach (['condition_verified', 'safe_for_delivery', 'safe_for_transport', 'recovered_verified', 'obstacle_resolved', 'dispute_resolved', 'operational_ready'] as $field) {
                if (array_key_exists($field, $findings)) $findings[$field] = filter_var($findings[$field], FILTER_VALIDATE_BOOLEAN);
            }
            $resolved = $service->resolve($incident, $shop, $data['resolution'], $data['note'], $storedPaths, $actor, $retryOptions, $data['responsible_party'] ?? null,
                ['request_key' => $data['request_key'], 'expected_revision' => $data['expected_revision'], 'findings' => $findings,
                    'evidence_digests' => array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $request->file('evidence_files', []))]);
            $this->deleteUncommittedEvidence($storedPaths, $resolved->evidencePaths());

            return response()->json(['incident' => $this->incidentPayload($resolved)]);
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($storedPaths, []);
            throw $exception;
        }
    }

    public function evidence(DeliveryIncident $incident, int $index)
    {
        $shop = $this->authorizedIncidentShop();
        $incident->loadMissing(['leg.shipment', 'batch']);
        $subjectShop = $incident->delivery_batch_id ? $incident->batch?->shop_owner_id : $incident->leg?->shipment?->shop_owner_id;
        abort_unless($subjectShop, 404);
        abort_unless((int) $incident->shop_owner_id === (int) $subjectShop && (int) $subjectShop === (int) $shop->id, 403);

        $path = $incident->evidencePaths()[$index] ?? null;
        abort_unless(is_string($path) && $this->isSafeEvidencePath($path), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizedIncidentShop(): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (! $user instanceof User || ! $user->shopOwner
            || (! $user->can('resolve-logistics-exceptions') && ! $user->can('assign-logistics-deliveries'))) {
            abort(403);
        }

        return $user->shopOwner;
    }

    private function authenticatedActor(bool $preferShopOwner = true): Authenticatable
    {
        $guards = $preferShopOwner ? ['shop_owner', 'user'] : ['user', 'shop_owner'];
        $actor = collect($guards)
            ->map(fn (string $guard) => Auth::guard($guard)->user())
            ->first(fn ($candidate) => $candidate instanceof Authenticatable);
        abort_unless($actor instanceof Authenticatable, 403);

        return $actor;
    }

    private function shopForActor(Authenticatable $actor): ?ShopOwner
    {
        if ($actor instanceof ShopOwner) {
            return $actor;
        }

        if (! $actor instanceof User || ! $actor->shop_owner_id) {
            return null;
        }

        return $actor->shopOwner ?: ShopOwner::query()->find($actor->shop_owner_id);
    }

    private function logDenial(
        Authenticatable $actor,
        ShopOwner $shop,
        string $action,
        ?string $reasonCategory,
    ): void {
        Log::warning('Logistics action denied', [
            'domain' => 'logistics',
            'action' => $action,
            'actor_guard' => $actor instanceof ShopOwner ? 'shop_owner' : 'user',
            'actor_type' => $actor::class,
            'shop_id' => (int) $shop->id,
            'denial_category' => $reasonCategory,
            'route_name' => (string) (request()->route()?->getName() ?? ''),
            'correlation_id' => request()->header('X-Correlation-ID'),
            'request_id' => request()->header('X-Request-ID'),
        ]);
    }

    private function incidentPayload(DeliveryIncident $incident): DeliveryIncident
    {
        $incident->setAttribute('loss', $incident->lossWorkflow(Auth::guard('user')->user()));
        $incident->setAttribute('eligibility', app(DeliveryIncidentService::class)->eligibility($incident, data_get($incident->recovery_context, 'findings', [])));
        $incident->setAttribute('evidence_urls', collect($incident->evidencePaths())
            ->values()
            ->map(fn ($path, $index) => $this->isSafeEvidencePath($path)
                && Storage::disk('local')->exists($path)
                ? route('api.logistics.incidents.evidence', ['incident' => $incident, 'index' => $index])
                : null)
            ->filter()
            ->values()
            ->all());

        return $incident;
    }

    private function storeEvidenceFiles(array $files, string $directory): array
    {
        $paths = [];
        try {
            foreach ($files as $file) {
                $path = $file->store($directory, 'local');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Incident evidence could not be stored.');
                }
                $paths[] = $path;
            }
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($paths, []);
            throw $exception;
        }

        return $paths;
    }

    private function deleteUncommittedEvidence(array $storedPaths, array $persistedPaths): void
    {
        foreach (array_diff($storedPaths, $persistedPaths) as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function evidenceFileRules(): array
    {
        return [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:10240',
        ];
    }

    private function isSafeEvidencePath(mixed $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'incident-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
