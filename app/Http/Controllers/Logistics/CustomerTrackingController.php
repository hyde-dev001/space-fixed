<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\HandoffProof;
use App\Models\Logistics\Shipment;
use App\Services\Logistics\CustomerTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Image\Enums\ImageDriver as ImageDriverEnum;
use Spatie\Image\Image;

class CustomerTrackingController extends Controller
{
    public function show(Shipment $shipment, CustomerTrackingService $tracking): Response|JsonResponse
    {
        $customer = Auth::guard('user')->user();

        if (! $customer || ! $tracking->customerOwnsShipment($shipment, (int) $customer->id)) {
            abort(403);
        }

        $payload = $tracking->payload($shipment);
        if (request()->expectsJson()) {
            return response()->json(['shipment' => $payload]);
        }

        return Inertia::render('UserSide/Tracking/ShipmentTracking', [
            'shipment' => $payload,
        ]);
    }

    public function attemptProof(Shipment $shipment, DeliveryAttempt $attempt, CustomerTrackingService $tracking)
    {
        $customer = Auth::guard('user')->user();
        $attempt->loadMissing('leg');

        if (! $customer || ! $tracking->customerOwnsShipment($shipment, (int) $customer->id)) {
            abort(403);
        }
        if ((int) $attempt->leg?->shipment_id !== (int) $shipment->id) {
            abort(403);
        }
        $path = (string) $attempt->getRawOriginal('file_path');
        abort_unless($this->isSafeAttemptEvidencePath($path), 404);
        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $private = Storage::disk('local');
        abort_unless($private->exists($path), 404);

        return $private->response($path, null, $headers);
    }

    public function deliveryProof(Shipment $shipment, HandoffProof $proof, CustomerTrackingService $tracking)
    {
        $customer = Auth::guard('user')->user();
        $proof->loadMissing('leg.shipment');

        if (! $customer || ! $tracking->customerOwnsShipment($shipment, (int) $customer->id)) {
            abort(403);
        }
        if ((int) $proof->leg?->shipment_id !== (int) $shipment->id) {
            abort(403);
        }
        abort_unless(
            $proof->leg->status->value === 'delivered'
            && in_array($proof->handoff_type, ['delivery', 'receive'], true)
            && $proof->proof_type === 'photo'
            && $proof->review_status === 'approved',
            404
        );

        $disk = Storage::disk('local');
        abort_unless($proof->file_path && $disk->exists($proof->file_path), 404);
        abort_unless(extension_loaded('gd'), 503);

        $mime = $disk->mimeType($proof->file_path);
        $format = match ($mime) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => abort(404),
        };

        try {
            $encoded = Image::useImageDriver(ImageDriverEnum::Gd)
                ->loadFile($disk->path($proof->file_path))
                ->base64($format, false);
            $bytes = base64_decode($encoded, true);
        } catch (\Throwable) {
            abort(404);
        }
        abort_unless(is_string($bytes), 404);

        $disposition = request()->boolean('download') ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "{$disposition}; filename=\"delivery-proof-{$proof->id}.{$format}\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isSafeAttemptEvidencePath(string $path): bool
    {
        return str_starts_with($path, 'logistics-attempt/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
