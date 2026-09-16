<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use App\Models\ShowroomProductPlacement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ShowroomPlacementService
{
    public const EDIT_PERMISSIONS = [
        'access-product-management',
        'access-product-upload-staff',
    ];

    public function actorShopOwnerId(Request $request): ?int
    {
        if ($owner = $request->user('shop_owner')) {
            return (int) $owner->getKey();
        }

        $staff = $request->user('user');

        return $staff && $staff->shop_owner_id
            ? (int) $staff->shop_owner_id
            : null;
    }

    public function canEdit(Request $request, int $shopOwnerId): bool
    {
        return Schema::hasTable('showroom_product_placements')
            && $this->canManage($request, $shopOwnerId);
    }

    public function canManage(Request $request, int $shopOwnerId): bool
    {
        $owner = $request->user('shop_owner');
        if ($owner && (int) $owner->getKey() === $shopOwnerId) {
            return true;
        }

        $staff = $request->user('user');

        return (bool) (
            $staff
            && (int) ($staff->shop_owner_id ?? 0) === $shopOwnerId
            && $staff->hasAnyPermission(self::EDIT_PERMISSIONS)
        );
    }

    public function canManageWallArt(Request $request, int $shopOwnerId): bool
    {
        $owner = $request->user('shop_owner');

        return $owner
            && (int) $owner->getKey() === $shopOwnerId;
    }

    /** @return array{left: string|null, right: string|null} */
    public function wallArtPathsForShop(int $shopOwnerId): array
    {
        if ($this->hasWallArtColumns()) {
            $shop = ShopOwner::query()
                ->select(['showroom_left_wall_art_path', 'showroom_right_wall_art_path'])
                ->whereKey($shopOwnerId)
                ->first();

            return [
                'left' => $shop?->showroom_left_wall_art_path ?? $this->legacyWallArtPath($shopOwnerId, 'left'),
                'right' => $shop?->showroom_right_wall_art_path ?? $this->legacyWallArtPath($shopOwnerId, 'right'),
            ];
        }

        return [
            'left' => $this->legacyWallArtPath($shopOwnerId, 'left'),
            'right' => $this->legacyWallArtPath($shopOwnerId, 'right'),
        ];
    }

    public function replaceWallArt(int $shopOwnerId, string $wall, UploadedFile $image): string
    {
        $column = $this->wallArtColumn($wall);
        if (!$this->hasWallArtColumns()) {
            return $this->replaceLegacyWallArt($shopOwnerId, $wall, $image);
        }

        $shop = ShopOwner::query()->whereKey($shopOwnerId)->firstOrFail();
        $path = $image->store("showroom/wall-art/{$shopOwnerId}", 'public');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store wall art.');
        }

        $previousPath = $shop->{$column};
        try {
            $shop->forceFill([$column => $path])->saveOrFail();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if (is_string($previousPath) && $previousPath !== '') {
            Storage::disk('public')->delete($previousPath);
        }
        foreach ($this->legacyWallArtFiles($shopOwnerId, $wall) as $legacyPath) {
            Storage::disk('public')->delete($legacyPath);
        }

        return $path;
    }

    public function removeWallArt(int $shopOwnerId, string $wall): void
    {
        $column = $this->wallArtColumn($wall);
        if (!$this->hasWallArtColumns()) {
            foreach ($this->legacyWallArtFiles($shopOwnerId, $wall) as $path) {
                Storage::disk('public')->delete($path);
            }

            return;
        }

        $shop = ShopOwner::query()->whereKey($shopOwnerId)->firstOrFail();
        $previousPath = $shop->{$column};
        $shop->forceFill([$column => null])->saveOrFail();

        if (is_string($previousPath) && $previousPath !== '') {
            Storage::disk('public')->delete($previousPath);
        }
        foreach ($this->legacyWallArtFiles($shopOwnerId, $wall) as $legacyPath) {
            Storage::disk('public')->delete($legacyPath);
        }
    }

    public function placementsForShop(int $shopOwnerId): Collection
    {
        if (!Schema::hasTable('showroom_product_placements')) {
            return new Collection();
        }

        return ShowroomProductPlacement::query()
            ->select(['product_id', 'slot_key'])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereHas('product', function ($query) use ($shopOwnerId): void {
                $query->where('shop_owner_id', $shopOwnerId)
                    ->where('is_active', true)
                    ->where('is_featured', true);
            })
            ->orderBy('slot_key')
            ->get();
    }

    public function showroomSlotLimit(int $shopOwnerId): int
    {
        $shop = ShopOwner::query()
            ->approved()
            ->whereKey($shopOwnerId)
            ->first();

        if (!$shop || !$this->isRetailCapable($shop->business_type)) {
            return 0;
        }

        $subscription = ShopOwnerSubscription::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->showroomEntitled()
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if (!$subscription) {
            return 0;
        }

        $planLimit = (int) ($subscription->premiumPlan?->showroom_slot_limit ?? 0);
        $subscriptionLimit = (int) ($subscription->showroom_slot_limit ?? 0);
        $planLabel = strtolower(trim((string) ($subscription->plan_code ?: $subscription->premiumPlan?->name)));
        $fallback = str_contains($planLabel, 'basic')
            ? 48
            : (str_contains($planLabel, 'premium') ? 84 : 60);

        return max(0, min(150, $planLimit > 0 ? $planLimit : ($subscriptionLimit > 0 ? $subscriptionLimit : $fallback)));
    }

    public function move(
        int $shopOwnerId,
        int $productId,
        string $fromSlotKey,
        string $toSlotKey,
    ): Collection {
        // ponytail: one shop-row lock serializes layout edits; split to slot locks if throughput matters.
        return DB::transaction(function () use ($shopOwnerId, $productId, $fromSlotKey, $toSlotKey): Collection {
            $shop = ShopOwner::query()->whereKey($shopOwnerId)->lockForUpdate()->firstOrFail();
            $limit = $this->showroomSlotLimit((int) $shop->getKey());
            if ($limit < 1) {
                throw new AuthorizationException('A valid premium showroom subscription is required.');
            }
            $this->assertSlotWithinLimit($fromSlotKey, $limit);
            $this->assertSlotWithinLimit($toSlotKey, $limit);

            $product = Product::query()
                ->whereKey($productId)
                ->where('shop_owner_id', $shop->getKey())
                ->where('is_active', true)
                ->where('is_featured', true)
                ->first();
            if (!$product) {
                throw new AuthorizationException('Product is not available in this showroom.');
            }

            $rows = ShowroomProductPlacement::query()
                ->where('shop_owner_id', $shop->getKey())
                ->lockForUpdate()
                ->get();
            $source = $rows->firstWhere('product_id', $product->getKey());
            $sourceOccupant = $rows->firstWhere('slot_key', $fromSlotKey);

            if ($source && $source->slot_key !== $fromSlotKey) {
                throw ValidationException::withMessages(['from_slot_key' => 'The source slot is stale.']);
            }
            if (!$source && $sourceOccupant) {
                throw ValidationException::withMessages(['from_slot_key' => 'The source slot is occupied.']);
            }

            if ($fromSlotKey !== $toSlotKey) {
                $target = $rows->firstWhere('slot_key', $toSlotKey);
                if ($source) {
                    $source->update(['slot_key' => 'swap-temp-' . Str::uuid()]);
                    if ($target) {
                        $target->update(['slot_key' => $fromSlotKey]);
                    }
                    $source->update(['slot_key' => $toSlotKey]);
                } else {
                    if ($target) {
                        $target->update(['slot_key' => $fromSlotKey]);
                    }
                    ShowroomProductPlacement::create([
                        'shop_owner_id' => $shop->getKey(),
                        'product_id' => $product->getKey(),
                        'slot_key' => $toSlotKey,
                    ]);
                }
            }

            return $this->placementsForShop((int) $shop->getKey());
        });
    }

    private function assertSlotWithinLimit(string $slotKey, int $limit): void
    {
        if (!preg_match('/^slot-([0-9]+)$/', $slotKey, $matches)) {
            throw ValidationException::withMessages(['slot_key' => 'The shelf slot is invalid.']);
        }

        if ((int) $matches[1] >= $limit) {
            throw ValidationException::withMessages(['slot_key' => 'The shelf slot is outside your showroom plan.']);
        }
    }

    private function wallArtColumn(string $wall): string
    {
        return match ($wall) {
            'left' => 'showroom_left_wall_art_path',
            'right' => 'showroom_right_wall_art_path',
            default => throw new InvalidArgumentException('The wall art side is invalid.'),
        };
    }

    private function hasWallArtColumns(): bool
    {
        return Schema::hasColumn('shop_owners', 'showroom_left_wall_art_path')
            && Schema::hasColumn('shop_owners', 'showroom_right_wall_art_path');
    }

    private function legacyWallArtPath(int $shopOwnerId, string $wall): ?string
    {
        return $this->legacyWallArtFiles($shopOwnerId, $wall)[0] ?? null;
    }

    /** @return list<string> */
    private function legacyWallArtFiles(int $shopOwnerId, string $wall): array
    {
        $prefix = $wall . '.';
        $directory = "showroom/wall-art/{$shopOwnerId}";

        return array_values(array_filter(
            Storage::disk('public')->files($directory),
            static fn (string $path): bool => str_starts_with(basename($path), $prefix),
        ));
    }

    private function replaceLegacyWallArt(int $shopOwnerId, string $wall, UploadedFile $image): string
    {
        $extension = strtolower((string) $image->extension());
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $directory = "showroom/wall-art/{$shopOwnerId}";
        $path = $image->storeAs($directory, $wall . '.' . Str::uuid() . '.' . $extension, 'public');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store wall art.');
        }

        foreach ($this->legacyWallArtFiles($shopOwnerId, $wall) as $previousPath) {
            if ($previousPath !== $path) {
                Storage::disk('public')->delete($previousPath);
            }
        }

        return $path;
    }

    private function isRetailCapable(?string $businessType): bool
    {
        $normalized = strtolower(trim((string) $businessType));

        return $normalized === 'retail'
            || $normalized === 'both'
            || (str_contains($normalized, 'retail') && str_contains($normalized, 'repair'));
    }
}
