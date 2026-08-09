<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryIncident;
use App\Models\Logistics\RiderProfile;
use App\Models\Logistics\ShipmentLeg;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\DeliveryIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DeliveryIncidentController extends Controller
{
    public function store(Request $request, ShipmentLeg $leg, DeliveryIncidentService $service): JsonResponse
    {
        $user = Auth::guard('user')->user();
        abort_unless($user instanceof User, 403);
        $leg->loadMissing('shipment');
        abort_unless($leg->shipment, 404);
        abort_unless((int) $user->shop_owner_id === (int) $leg->shipment->shop_owner_id, 403);
        $rider = RiderProfile::query()
            ->where('shop_owner_id', $leg->shipment->shop_owner_id)
            ->where('linked_type', User::class)
            ->where('linked_id', $user->id)
            ->firstOrFail();
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
        $shop = Auth::guard('shop_owner')->user();
        if (!$shop) {
            $user = Auth::guard('user')->user();
            abort_unless($user?->shop_owner_id && $user->can('resolve-logistics-exceptions'), 403);
            $shop = ShopOwner::findOrFail($user->shop_owner_id);
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
