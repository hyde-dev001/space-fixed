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
            'photo_files' => ['required', 'array', 'min:1', 'max:5'],
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

    public function resolve(Request $request, DeliveryIncident $incident, DeliveryIncidentService $service): JsonResponse
    {
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
            'note' => ['required', 'string', 'max:1000'],
            'evidence_files' => [
                Rule::requiredIf($request->input('resolution') === 'loss_confirmed'),
                'nullable',
                'array',
                'min:1',
                'max:5',
            ],
            'evidence_files.*' => $this->evidenceFileRules(),
        ]);
        $storedPaths = $this->storeEvidenceFiles($request->file('evidence_files', []), "incident-evidence/incident-{$incident->id}");

        try {
            $resolved = $service->resolve($incident, $shop, $data['resolution'], $data['note'], $storedPaths);
            $this->deleteUncommittedEvidence($storedPaths, $resolved->photo_paths ?? []);

            return response()->json(['incident' => $this->incidentPayload($resolved)]);
        } catch (\Throwable $exception) {
            $this->deleteUncommittedEvidence($storedPaths, []);
            throw $exception;
        }
    }

    public function evidence(DeliveryIncident $incident, int $index)
    {
        $shop = $this->authorizedIncidentShop();
        $incident->loadMissing('leg.shipment');
        abort_unless($incident->leg?->shipment, 404);
        abort_unless((int) $incident->shop_owner_id === (int) $incident->leg->shipment->shop_owner_id, 403);
        abort_unless((int) $incident->leg->shipment->shop_owner_id === (int) $shop->id, 403);

        $path = $incident->photo_paths[$index] ?? null;
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
        $incident->setAttribute('evidence_urls', collect($incident->photo_paths ?? [])
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
