<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\DeliveryDispute;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DeliveryDisputeEvidenceController extends Controller
{
    public function show(DeliveryDispute $dispute, string $mediaId)
    {
        $shopOwnerId = $this->authorizedShopOwnerId();
        abort_unless((int) $dispute->shop_owner_id === $shopOwnerId, 403);

        $media = collect($dispute->evidence_media ?? [])
            ->first(fn (mixed $entry): bool => is_array($entry) && (string) ($entry['id'] ?? '') === $mediaId);
        abort_unless(is_array($media), 404);

        $path = $media['path'] ?? null;
        abort_unless(
            is_string($path)
            && str_starts_with($path, 'delivery-dispute-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\'),
            404,
        );

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (is_string($media['mime_type'] ?? null) && $media['mime_type'] !== '') {
            $headers['Content-Type'] = $media['mime_type'];
        }

        return $disk->response($path, null, $headers);
    }

    private function authorizedShopOwnerId(): int
    {
        $shop = Auth::guard('shop_owner')->user();
        if ($shop instanceof ShopOwner) {
            return (int) $shop->id;
        }

        $user = Auth::guard('user')->user();
        abort_unless($user instanceof User && $user->shop_owner_id, 403);
        abort_unless(
            $user->can('access-staff-job-orders')
            || $user->can('resolve-logistics-exceptions')
            || $user->can('assign-logistics-deliveries'),
            403,
        );

        return (int) $user->shop_owner_id;
    }
}
