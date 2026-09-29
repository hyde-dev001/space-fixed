# Connected showroom editing and lounge game Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax with - [ ] for tracking.

**Goal:** Add secure owner/staff shelf placement with automatic persistence, dynamic shop branding, and a couch-triggered casual XOX game to the existing virtual showroom.

**Architecture:** Keep the authored Three.js room and fixed shelf geometry. Add stable slot keys and a tenant-scoped placement table; a transaction-backed endpoint performs moves and swaps, while the Inertia page supplies canonical placements and an edit capability flag. Keep placement mapping and XOX rules as small pure TypeScript modules, and keep couch/game state local to VirtualShowroom.

**Tech Stack:** Laravel 12, PHP 8.2, Eloquent, Inertia 2, React 18, TypeScript 5.7, Three.js 0.182, Vitest, PHPUnit, Tailwind CSS 4, existing fetch/CSRF setup.

**Execution status:** Implemented inline on the feature branch. Focused frontend and backend checks plus the production build pass. A local browser smoke check verified the canvas, dynamic shop branding, and edit control; material image requests were unavailable from the isolated Vite smoke host, so production browser serving remains the final environment check.

## Global Constraints

- Keep the current Three.js room, authored furniture, capacity rules, and product image behavior.
- Use fixed authored shelf slots represented by stable keys; do not persist arbitrary 3D coordinates.
- Save automatically after every successful drop.
- Dropping onto an occupied slot swaps the two products.
- Only the shop owner or an authorized staff account linked to that shop may edit its showroom.
- Derive the tenant from the authenticated actor; never trust a client-supplied shop ID.
- Replace showroom SOLESPACE labels with the resolved shop name and retain the existing sign subtitles.
- Seating requires approaching a couch with WASD and pressing E; movement is frozen while seated.
- XOX is local to the visit, with the visitor as X and a casual/random bot as O.
- Do not add a dependency; use existing React, browser, Laravel, and Three.js APIs.
- Keep keyboard access, 44px touch targets, high contrast, reduced-motion support, and narrow-screen containment.
- Do not change premium plan limits, subscription rules, product catalog behavior, or furniture editing.

---

## File map

- Create: database/migrations/2026_09_14_000002_create_showroom_product_placements_table.php — tenant-scoped placement schema.
- Create: app/Models/ShowroomProductPlacement.php — placement Eloquent model and relations.
- Create: app/Services/ShowroomPlacementService.php — actor authorization, entitlement checks, canonical placements, and atomic moves.
- Create: app/Http/Controllers/Api/ShowroomPlacementController.php — validated placement endpoint.
- Create: resources/js/Pages/UserSide/Products/showroomPlacement.ts — stable assignment normalization and swaps.
- Create: resources/js/Pages/UserSide/Products/showroomPlacement.test.ts — pure placement tests.
- Create: resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts — board rules and random bot move.
- Create: resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts — pure XOX tests.
- Create: resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx — accessible local XOX overlay.
- Create: tests/Feature/ShowroomPlacementTest.php — migration, endpoint, tenant, entitlement, and swap coverage.
- Modify: resources/js/Pages/UserSide/Products/showroomLayout.ts — stable slot keys and couch interaction points.
- Modify: resources/js/Pages/UserSide/Products/showroomLayout.test.ts — key and seat contracts.
- Modify: resources/js/Pages/UserSide/Products/showroomScene.ts — dynamic signs and edit hit targets.
- Modify: app/Http/Controllers/UserSide/LandingPageController.php — placement payload and edit capability.
- Modify: routes/api.php — authenticated owner/staff placement route.
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx — page prop types and pass-through.
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts — dynamic name/edit contracts.
- Modify: resources/js/Pages/UserSide/Products/VirtualShowroom.tsx — placement editor, couch proximity, seating, and XOX integration.

The existing untracked public/build-test directory is not part of this work and must remain unstaged.

## Task 1: Stable showroom slots and pure placement mapping

**Files:**
- Modify: resources/js/Pages/UserSide/Products/showroomLayout.ts
- Modify: resources/js/Pages/UserSide/Products/showroomLayout.test.ts
- Create: resources/js/Pages/UserSide/Products/showroomPlacement.ts
- Create: resources/js/Pages/UserSide/Products/showroomPlacement.test.ts

**Interfaces:**
- ShowroomSlot.key is slot- followed by the zero-based authored slot index.
- ShowroomSeat has key, interactionPosition, cameraPosition, and lookAt.
- resolveShowroomPlacements(products, slots, saved) returns valid saved assignments followed by deterministic assignments for remaining products.
- swapShowroomPlacements(assignments, productId, sourceSlotKey, targetSlotKey) returns a moved or swapped copy.
- getNearbyShowroomSeat(x, z, seats, radius) returns a seat or null.

- [ ] **Step 1: Write failing layout and mapper tests**

Add getNearbyShowroomSeat to the existing showroomLayout.test.ts import, then add:

    it('keeps slot keys stable as capacity grows', () => {
      const sixty = getShowroomLayout(60).slots;
      const full = getShowroomLayout(150).slots;
      expect(sixty.map((slot) => slot.key)).toEqual(full.slice(0, 60).map((slot) => slot.key));
      expect(new Set(full.map((slot) => slot.key)).size).toBe(150);
      expect(full[0].key).toBe('slot-0');
      expect(full[149].key).toBe('slot-149');
    });

    it('exposes walkable couch interaction points', () => {
      const layout = getShowroomLayout(60);
      expect(layout.seats).toHaveLength(layout.lounges.length);
      for (const seat of layout.seats) {
        expect(canWalkTo(seat.interactionPosition[0], seat.interactionPosition[1], layout.colliders)).toBe(true);
        expect(getNearbyShowroomSeat(
          seat.interactionPosition[0],
          seat.interactionPosition[1],
          layout.seats,
          1.5,
        )?.key).toBe(seat.key);
      }
    });

Create showroomPlacement.test.ts:

    import { describe, expect, it } from 'vitest';
    import { getShowroomLayout } from './showroomLayout';
    import { resolveShowroomPlacements, swapShowroomPlacements } from './showroomPlacement';

    describe('showroom placement mapping', () => {
      const slots = getShowroomLayout(4).slots;
      const products = [{ id: 10 }, { id: 20 }, { id: 30 }];

      it('uses valid saved assignments and fills the remaining products', () => {
        expect(resolveShowroomPlacements(products, slots, [
          { productId: 30, slotKey: 'slot-2' },
          { productId: 999, slotKey: 'slot-1' },
          { productId: 20, slotKey: 'unknown' },
        ])).toEqual([
          { productId: 10, slotKey: 'slot-0' },
          { productId: 20, slotKey: 'slot-1' },
          { productId: 30, slotKey: 'slot-2' },
        ]);
      });

      it('swaps occupants on an occupied drop and moves into an empty slot', () => {
        const assignments = [
          { productId: 10, slotKey: 'slot-0' },
          { productId: 20, slotKey: 'slot-1' },
        ];
        expect(swapShowroomPlacements(assignments, 10, 'slot-0', 'slot-1')).toEqual([
          { productId: 10, slotKey: 'slot-1' },
          { productId: 20, slotKey: 'slot-0' },
        ]);
        expect(swapShowroomPlacements(assignments, 10, 'slot-0', 'slot-3')).toEqual([
          { productId: 10, slotKey: 'slot-3' },
          { productId: 20, slotKey: 'slot-1' },
        ]);
      });
    });

- [ ] **Step 2: Run the focused tests and verify they fail**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts

Expected: the new key, seat, and mapper assertions fail because the interfaces and helpers do not exist yet.

- [ ] **Step 3: Add stable keys, seats, and the mapper**

In showroomLayout.ts add:

    export interface ShowroomSlot {
      key: string;
      position: [number, number, number];
      rotationY: number;
      kind: 'feature' | 'island' | 'wall';
    }

    export interface ShowroomSeat {
      key: string;
      interactionPosition: [number, number];
      cameraPosition: [number, number, number];
      lookAt: [number, number, number];
    }

Use this helper for every existing slot-generation loop, preserving every authored position and order:

    const pushSlot = (
      position: [number, number, number],
      rotationY: number,
      kind: ShowroomSlot['kind'],
    ) => {
      slots.push({ key: 'slot-' + slots.length, position, rotationY, kind });
    };

Add seats in the walkable gap in front of each existing couch and return them beside lounges:

    const seats: ShowroomSeat[] = lounges.map((lounge, index) => {
      const side = lounge.x < 0 ? 1 : -1;
      return {
        key: 'lounge-seat-' + index,
        interactionPosition: [lounge.x + side * 3.25, lounge.z + 1.8],
        cameraPosition: [lounge.x + side * 3.5, 2.05, lounge.z + 1.8],
        lookAt: [lounge.x, 1.25, lounge.z + 1.8],
      };
    });

    export const getNearbyShowroomSeat = (
      x: number,
      z: number,
      seats: readonly ShowroomSeat[],
      radius = 2.4,
    ) => seats.find((seat) => {
      const dx = x - seat.interactionPosition[0];
      const dz = z - seat.interactionPosition[1];
      return dx * dx + dz * dz <= radius * radius;
    }) ?? null;

Create showroomPlacement.ts:

    import type { ShowroomSlot } from './showroomLayout';

    export interface ShowroomPlacement {
      productId: number;
      slotKey: string;
    }

    export interface ShowroomProductIdentity {
      id: number;
    }

    export const resolveShowroomPlacements = (
      products: readonly ShowroomProductIdentity[],
      slots: readonly ShowroomSlot[],
      saved: readonly ShowroomPlacement[],
    ): ShowroomPlacement[] => {
      const productIds = new Set(products.map((product) => product.id));
      const slotOrder = new Map(slots.map((slot, index) => [slot.key, index]));
      const usedProducts = new Set<number>();
      const usedSlots = new Set<string>();
      const resolved: ShowroomPlacement[] = [];

      for (const placement of saved) {
        if (
          !productIds.has(placement.productId)
          || !slotOrder.has(placement.slotKey)
          || usedProducts.has(placement.productId)
          || usedSlots.has(placement.slotKey)
        ) continue;
        usedProducts.add(placement.productId);
        usedSlots.add(placement.slotKey);
        resolved.push({ ...placement });
      }

      const freeSlots = slots.filter((slot) => !usedSlots.has(slot.key));
      let freeSlotCursor = 0;
      for (const product of products) {
        if (usedProducts.has(product.id)) continue;
        const slot = freeSlots[freeSlotCursor++];
        if (!slot) break;
        usedProducts.add(product.id);
        usedSlots.add(slot.key);
        resolved.push({ productId: product.id, slotKey: slot.key });
      }

      return resolved.sort((a, b) => slotOrder.get(a.slotKey)! - slotOrder.get(b.slotKey)!);
    };

    export const swapShowroomPlacements = (
      assignments: readonly ShowroomPlacement[],
      productId: number,
      sourceSlotKey: string,
      targetSlotKey: string,
    ): ShowroomPlacement[] => {
      if (sourceSlotKey === targetSlotKey) return assignments.map((assignment) => ({ ...assignment }));
      const target = assignments.find((assignment) => assignment.slotKey === targetSlotKey);
      return assignments.map((assignment) => {
        if (assignment.productId === productId) return { ...assignment, slotKey: targetSlotKey };
        if (target && assignment.productId === target.productId) return { ...assignment, slotKey: sourceSlotKey };
        return { ...assignment };
      });
    };

- [ ] **Step 4: Run the tests and commit the pure layout deliverable**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts

Expected: all layout and placement tests pass.

Commit:

    git add resources/js/Pages/UserSide/Products/showroomLayout.ts resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts
    git commit -m "feat: add stable showroom placement slots"

## Task 2: Placement storage, authorization, and atomic move endpoint

**Files:**
- Create: database/migrations/2026_09_14_000002_create_showroom_product_placements_table.php
- Create: app/Models/ShowroomProductPlacement.php
- Create: app/Services/ShowroomPlacementService.php
- Create: app/Http/Controllers/Api/ShowroomPlacementController.php
- Modify: routes/api.php
- Create: tests/Feature/ShowroomPlacementTest.php

**Interfaces:**
- PUT /api/showroom/placements accepts product_id, from_slot_key, and to_slot_key.
- The JSON response is success plus placements containing product_id and slot_key.
- Service methods are actorShopOwnerId(Request): ?int, canEdit(Request, int): bool, placementsForShop(int): Collection, showroomSlotLimit(int): int, and move(int, int, string, string): Collection.

- [ ] **Step 1: Write the failing feature tests**

Create tests/Feature/ShowroomPlacementTest.php using RefreshDatabase. Build an approved retail ShopOwner, a PremiumPlan, an active ShopOwnerSubscription whose starts_at is yesterday and ends_at is tomorrow, and active featured Product records. Create the user permission with Permission::findOrCreate before assigning it to a linked User.

Use these concrete helpers at the top of the test class:

    private function shopWithSubscription(int $limit = 4): ShopOwner
    {
        $shop = ShopOwner::factory()->approved()->create(['business_type' => 'retail']);
        $plan = PremiumPlan::create([
            'plan_code' => 'showroom-' . uniqid(),
            'name' => 'Showroom test',
            'description' => 'Showroom placement test plan',
            'price' => 249,
            'duration_days' => 30,
            'showroom_slot_limit' => $limit,
            'status' => 'active',
        ]);
        ShopOwnerSubscription::create([
            'shop_owner_id' => $shop->id,
            'premium_plan_id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'showroom_slot_limit' => $limit,
            'status' => 'active',
            'paymongo_session_id' => 'session-' . uniqid(),
            'paymongo_payment_id' => 'payment-' . uniqid(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        return $shop;
    }

    private function shoe(ShopOwner $shop, string $name): Product
    {
        return Product::create([
            'shop_owner_id' => $shop->id,
            'name' => $name,
            'description' => 'Placement test shoe',
            'price' => 2500,
            'brand' => 'Test',
            'category' => 'shoes',
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => true,
            'main_image' => '/images/test-shoe.jpg',
        ]);
    }

    public function test_owner_can_move_and_swap_featured_shoes(): void
    {
        $shop = $this->shopWithSubscription();
        $first = $this->shoe($shop, 'First');
        $second = $this->shoe($shop, 'Second');

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $first->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertOk();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $second->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertOk();

        $this->assertDatabaseHas('showroom_product_placements', [
            'shop_owner_id' => $shop->id,
            'product_id' => $first->id,
            'slot_key' => 'slot-0',
        ]);
        $this->assertDatabaseHas('showroom_product_placements', [
            'shop_owner_id' => $shop->id,
            'product_id' => $second->id,
            'slot_key' => 'slot-1',
        ]);
    }

    public function test_linked_staff_with_product_permission_can_edit_only_their_shop(): void
    {
        Permission::findOrCreate('access-product-management', 'user');
        $shop = $this->shopWithSubscription();
        $otherShop = $this->shopWithSubscription();
        $shoe = $this->shoe($shop, 'Staff shoe');
        $staff = User::factory()->create(['shop_owner_id' => $shop->id, 'role' => 'STAFF']);
        $staff->givePermissionTo('access-product-management');

        $this->actingAs($staff, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-2',
            ])->assertOk();

        $foreignShoe = $this->shoe($otherShop, 'Foreign shoe');
        $this->actingAs($staff, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $foreignShoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertForbidden();
    }

    public function test_customer_cannot_write_and_invalid_slots_are_rejected(): void
    {
        $shop = $this->shopWithSubscription(1);
        $shoe = $this->shoe($shop, 'Protected shoe');
        $customer = User::factory()->create(['shop_owner_id' => null, 'role' => 'CUSTOMER']);

        $this->actingAs($customer, 'user')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertForbidden();

        $this->actingAs($shop, 'shop_owner')
            ->putJson('/api/showroom/placements', [
                'product_id' => $shoe->id,
                'from_slot_key' => 'slot-0',
                'to_slot_key' => 'slot-1',
            ])->assertStatus(422);
    }

Include a page payload test that creates a saved row, requests the showroom as the owner, and asserts the Inertia data contains can_edit_showroom true and the saved slot key.

- [ ] **Step 2: Run the feature tests and verify they fail**

Run:

    php artisan test tests/Feature/ShowroomPlacementTest.php

Expected: the placement table, route, service, and controller are not defined yet.

- [ ] **Step 3: Create the migration and model**

Create the migration:

    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('showroom_product_placements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('slot_key', 80);
                $table->timestamps();
                $table->unique(['shop_owner_id', 'product_id']);
                $table->unique(['shop_owner_id', 'slot_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('showroom_product_placements');
        }
    };

Create the model:

    <?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    final class ShowroomProductPlacement extends Model
    {
        use HasFactory;

        protected $fillable = ['shop_owner_id', 'product_id', 'slot_key'];

        public function shopOwner(): BelongsTo
        {
            return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
        }

        public function product(): BelongsTo
        {
            return $this->belongsTo(Product::class);
        }
    }

- [ ] **Step 4: Implement ShowroomPlacementService**

Use the user guard for staff and the shop_owner guard for owners, with the owner guard taking precedence if both sessions exist. Owner access is allowed for the resolved owner ID; staff access requires a non-null shop_owner_id and either access-product-management or access-product-upload-staff:

    public const EDIT_PERMISSIONS = [
        'access-product-management',
        'access-product-upload-staff',
    ];

    public function actorShopOwnerId(Request $request): ?int
    {
        if ($owner = $request->user('shop_owner')) return (int) $owner->getKey();
        $staff = $request->user('user');
        return $staff && $staff->shop_owner_id ? (int) $staff->shop_owner_id : null;
    }

    public function canEdit(Request $request, int $shopOwnerId): bool
    {
        $owner = $request->user('shop_owner');
        if ($owner && (int) $owner->getKey() === $shopOwnerId) return true;
        $staff = $request->user('user');
        return (bool) (
            $staff
            && (int) ($staff->shop_owner_id ?? 0) === $shopOwnerId
            && $staff->hasAnyPermission(self::EDIT_PERMISSIONS)
        );
    }

showroomSlotLimit must load an approved ShopOwner, require a retail-capable business type, use ShopOwnerSubscription::showroomEntitled(), and return a value clamped from 0 through 150. placementsForShop selects product_id and slot_key for active featured products and orders by slot_key.

Implement move in DB::transaction. Lock the shop_owner row first, validate both slot keys with /^slot-[0-9]+$/ and the current limit, validate the product with shop_owner_id/is_active/is_featured, and lock that shop's placement rows. Reject a stale source placement. If the source has no persisted row, allow it only when the source slot is free. For a target occupant, move it to the source slot and the moved product to the target slot; use a temporary unique slot key while both rows are locked:

    // ponytail: one shop-row lock serializes layout edits; split to slot locks if throughput matters.
    return DB::transaction(function () use ($shopOwnerId, $productId, $fromSlotKey, $toSlotKey): Collection {
        $shop = ShopOwner::whereKey($shopOwnerId)->lockForUpdate()->firstOrFail();
        $limit = $this->showroomSlotLimit($shop->getKey());
        $this->assertSlotWithinLimit($fromSlotKey, $limit);
        $this->assertSlotWithinLimit($toSlotKey, $limit);
        $product = Product::whereKey($productId)
            ->where('shop_owner_id', $shop->getKey())
            ->where('is_active', true)
            ->where('is_featured', true)
            ->firstOrFail();
        $rows = ShowroomProductPlacement::where('shop_owner_id', $shop->getKey())
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
                if ($target) $target->update(['slot_key' => $fromSlotKey]);
                $source->update(['slot_key' => $toSlotKey]);
            } else {
                if ($target) $target->update(['slot_key' => $fromSlotKey]);
                ShowroomProductPlacement::create([
                    'shop_owner_id' => $shop->getKey(),
                    'product_id' => $product->getKey(),
                    'slot_key' => $toSlotKey,
                ]);
            }
        }
        return $this->placementsForShop((int) $shop->getKey());
    });

Use AuthorizationException for failed canEdit, ValidationException for stale input, and never read a shop ID from request input.

- [ ] **Step 5: Add the controller and route**

Create ShowroomPlacementController:

    <?php

    namespace App\Http\Controllers\Api;

    use App\Http\Controllers\Controller;
    use App\Services\ShowroomPlacementService;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;

    final class ShowroomPlacementController extends Controller
    {
        public function update(Request $request, ShowroomPlacementService $placements): JsonResponse
        {
            $validated = $request->validate([
                'product_id' => ['required', 'integer', 'min:1'],
                'from_slot_key' => ['required', 'string', 'regex:/^slot-[0-9]+$/'],
                'to_slot_key' => ['required', 'string', 'regex:/^slot-[0-9]+$/'],
            ]);
            $shopOwnerId = $placements->actorShopOwnerId($request);
            abort_unless($shopOwnerId, 403, 'A shop-linked account is required.');
            abort_unless($placements->canEdit($request, $shopOwnerId), 403, 'You cannot edit this showroom.');
            $canonical = $placements->move(
                $shopOwnerId,
                (int) $validated['product_id'],
                $validated['from_slot_key'],
                $validated['to_slot_key'],
            );
            return response()->json([
                'success' => true,
                'placements' => $canonical->map(fn ($placement) => [
                    'product_id' => (int) $placement->product_id,
                    'slot_key' => $placement->slot_key,
                ])->values(),
            ]);
        }
    }

Add to routes/api.php, already mounted under /api:

    Route::middleware(['web', 'auth:user,shop_owner', 'throttle:120,1'])
        ->put('/showroom/placements', [\App\Http\Controllers\Api\ShowroomPlacementController::class, 'update'])
        ->name('api.showroom.placements.update');

- [ ] **Step 6: Run the feature tests and commit**

Run:

    php artisan test tests/Feature/ShowroomPlacementTest.php

Expected: all placement tests pass, including owner/staff tenant isolation, customer denial, slot validation, and the page payload assertion.

Commit:

    git add database/migrations/2026_09_14_000002_create_showroom_product_placements_table.php app/Models/ShowroomProductPlacement.php app/Services/ShowroomPlacementService.php app/Http/Controllers/Api/ShowroomPlacementController.php routes/api.php tests/Feature/ShowroomPlacementTest.php
    git commit -m "feat: persist secure showroom placements"

## Task 3: Page payload and dynamic shop branding

**Files:**
- Modify: app/Http/Controllers/UserSide/LandingPageController.php
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

**Interfaces:**
- The virtual showroom shop payload adds showroom_placements and can_edit_showroom.
- VirtualShowroom receives shopName, showroomPlacements, and canEditShowroom.

- [ ] **Step 1: Add failing payload and contract assertions**

Add owner, permitted linked staff, and customer page requests to ShowroomPlacementTest. Assert owner/staff get can_edit_showroom true for their own shop, customers get false, and every visitor gets the placement list. Extend the contract test by reading the scene source beside the existing page/showroom sources:

    const sceneSource = readFileSync(
      resolve('resources/js/Pages/UserSide/Products/showroomScene.ts'),
      'utf8',
    );

    expect(pageSource).toContain('showroom_placements');
    expect(pageSource).toContain('can_edit_showroom');
    expect(pageSource).toContain('shopName={shop.name}');
    expect(showroomSource).toContain('shopName');
    expect(showroomSource).toContain('canEditShowroom');

- [ ] **Step 2: Run the focused tests and verify they fail**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
    php artisan test tests/Feature/ShowroomPlacementTest.php

Expected: the new payload keys and prop pass-through are absent.

- [ ] **Step 3: Add the placement service to LandingPageController**

Inject ShowroomPlacementService. In virtual showroom mode, add to the existing shop array:

    'showroom_placements' => $forVirtualShowroom
        ? $this->showroomPlacements->placementsForShop((int) $shopOwner->id)
            ->map(fn ($placement) => [
                'product_id' => (int) $placement->product_id,
                'slot_key' => $placement->slot_key,
            ])->values()->all()
        : [],
    'can_edit_showroom' => $forVirtualShowroom
        && $this->showroomPlacements->canEdit(request(), (int) $shopOwner->id),

Keep existing product selection, premium resolution, and shop name behavior unchanged.

- [ ] **Step 4: Extend VirtualShowroomPage types and pass props**

Add:

    interface ShowroomPlacement {
      product_id: number;
      slot_key: string;
    }

    interface Shop {
      // existing fields remain
      showroom_placements?: ShowroomPlacement[];
      can_edit_showroom?: boolean;
    }

Pass:

    <VirtualShowroom
      products={products}
      shopName={shop.name}
      showroomPlacements={(shop.showroom_placements ?? []).map((placement) => ({
        productId: placement.product_id,
        slotKey: placement.slot_key,
      }))}
      canEditShowroom={shop.can_edit_showroom === true}
      isStandalonePage
      onFocusModeChange={setIsFocusMode}
      showroomSlotLimit={shop.showroom_slot_limit}
      showroomPlanCode={shop.showroom_plan_code}
      showroomPlanName={shop.showroom_plan_name}
    />

- [ ] **Step 5: Run tests and commit**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
    php artisan test tests/Feature/ShowroomPlacementTest.php

Expected: both commands pass and the Inertia payload contains the shop name, placement list, and correct edit flag.

Commit:

    git add app/Http/Controllers/UserSide/LandingPageController.php resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts tests/Feature/ShowroomPlacementTest.php
    git commit -m "feat: expose showroom ownership and branding data"

## Task 4: Scene signs and edit hit targets

**Files:**
- Modify: resources/js/Pages/UserSide/Products/showroomScene.ts
- Modify: resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

**Interfaces:**
- createShowroomScene(renderer, capacity, night, lowPower, productCount, shopName, enableSlotEditing) returns scene, layout, ready, slotTargets, and dispose.
- Each slot target is a transparent Mesh with userData.slotKey.

- [ ] **Step 1: Add the scene contract assertions**

    expect(sceneSource).toContain('enableSlotEditing');
    expect(sceneSource).toContain('slotTargets');
    expect(sceneSource).toContain('displayShopName');
    expect(sceneSource).not.toContain("sign('SOLESPACE'");

- [ ] **Step 2: Implement dynamic signs**

Normalize before drawing canvas signs:

    const displayShopName = shopName.trim().slice(0, 36) || 'The Gallery';

Replace only the two SOLESPACE sign titles:

    sign(displayShopName, 'THE SNEAKER GALLERY', 0, 5.55, -25.45, 7);
    sign('WELCOME TO ' + displayShopName, 'EXPLORE / DISCOVER / COLLECT', 0, 4.8, 23.7, 8, Math.PI);

Keep the gallery and edit sign copy unchanged.

- [ ] **Step 3: Add transparent shelf targets**

When enableSlotEditing is true, create one double-sided transparent PlaneGeometry per layout slot, position/rotate it like the card, set userData.slotKey, and return the meshes. Add the geometry and material to the existing disposal sets:

    const slotTargets: THREE.Mesh[] = [];
    if (enableSlotEditing) {
      const targetGeometry = new THREE.PlaneGeometry(1.9, 1.25);
      const targetMaterial = new THREE.MeshBasicMaterial({
        transparent: true,
        opacity: 0,
        depthWrite: false,
        side: THREE.DoubleSide,
      });
      geometries.add(targetGeometry);
      materials.add(targetMaterial);
      for (const slot of layout.slots) {
        const target = new THREE.Mesh(targetGeometry, targetMaterial);
        target.position.set(...slot.position);
        target.rotation.y = slot.rotationY;
        target.userData.slotKey = slot.key;
        scene.add(target);
        slotTargets.push(target);
      }
    }

Return slotTargets beside scene/layout/ready and dispose them with the existing scene traversal.

- [ ] **Step 4: Run focused tests and commit**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts resources/js/Pages/UserSide/Products/showroomLayout.test.ts

Expected: branding and slot-target contracts pass without changing authored slot geometry.

Commit:

    git add resources/js/Pages/UserSide/Products/showroomScene.ts resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
    git commit -m "feat: expose branded showroom shelf targets"

## Task 5: Local XOX rules and overlay component

**Files:**
- Create: resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts
- Create: resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts
- Create: resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx

**Interfaces:**
- Board is a nine-cell tuple of X, O, or null.
- applyMove(board, index, mark) returns a new board or the original board for an invalid move.
- getWinner(board) returns X, O, or null.
- isDraw(board) returns boolean.
- getRandomBotMove(board, random) returns an empty index or null.
- ShowroomTicTacToe props are open, onStandUp, and onClose.

- [ ] **Step 1: Write failing rule tests**

Create showroomTicTacToe.test.ts:

    import { describe, expect, it } from 'vitest';
    import { applyMove, getRandomBotMove, getWinner, isDraw, type TicTacToeBoard } from './showroomTicTacToeRules';

    describe('showroom XOX rules', () => {
      it('detects rows, columns, and diagonals', () => {
        expect(getWinner(['X', 'X', 'X', null, null, null, null, null, null])).toBe('X');
        expect(getWinner(['O', null, null, 'O', null, null, 'O', null, null])).toBe('O');
        expect(getWinner(['X', null, null, null, 'X', null, null, null, 'X'])).toBe('X');
      });

      it('rejects occupied moves and reports a full draw', () => {
        const board: TicTacToeBoard = ['X', 'O', 'X', 'X', 'O', 'O', 'O', 'X', 'X'];
        expect(applyMove(board, 0, 'O')).toEqual(board);
        expect(isDraw(board)).toBe(true);
        expect(getWinner(board)).toBeNull();
      });

      it('chooses only empty squares', () => {
        const board: TicTacToeBoard = ['X', null, 'O', null, 'X', null, null, 'O', null];
        const move = getRandomBotMove(board, () => 0.99);
        expect([1, 3, 5, 6, 8]).toContain(move);
        expect(board[move!]).toBeNull();
      });
    });

- [ ] **Step 2: Run tests and verify they fail**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts

Expected: the board helpers are missing.

- [ ] **Step 3: Implement the pure rules**

Create showroomTicTacToeRules.ts:

    export type Mark = 'X' | 'O';
    export type Cell = Mark | null;
    export type TicTacToeBoard = [Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell];

    const LINES = [
      [0, 1, 2], [3, 4, 5], [6, 7, 8],
      [0, 3, 6], [1, 4, 7], [2, 5, 8],
      [0, 4, 8], [2, 4, 6],
    ] as const;

    export const applyMove = (board: TicTacToeBoard, index: number, mark: Mark): TicTacToeBoard => {
      if (index < 0 || index > 8 || board[index] !== null) return board;
      const next = [...board] as TicTacToeBoard;
      next[index] = mark;
      return next;
    };

    export const getWinner = (board: TicTacToeBoard): Mark | null => {
      for (const [a, b, c] of LINES) {
        if (board[a] && board[a] === board[b] && board[a] === board[c]) return board[a];
      }
      return null;
    };

    export const isDraw = (board: TicTacToeBoard): boolean =>
      getWinner(board) === null && board.every((cell) => cell !== null);

    export const getRandomBotMove = (
      board: TicTacToeBoard,
      random: () => number = Math.random,
    ): number | null => {
      const empty = board.flatMap((cell, index) => cell === null ? [index] : []);
      if (empty.length === 0) return null;
      return empty[Math.min(empty.length - 1, Math.floor(random() * empty.length))];
    };

- [ ] **Step 4: Implement the overlay**

ShowroomTicTacToe owns board, turn, result, and one 350ms bot timeout. Apply X only on the player's turn and only to an empty square; after the X state, schedule one random legal O move. Clear the timeout on reset, close, and unmount. Render nine buttons with aria-labels XOX square 1 through XOX square 9, a status line, New game, Close game, and Stand up. Use the existing stone/white treatment and min-h-11 controls in an inset max-width panel.

- [ ] **Step 5: Run tests and commit**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts

Expected: all rule tests pass.

Commit:

    git add resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx
    git commit -m "feat: add casual showroom XOX game"

## Task 6: Integrate owner drag/drop and couch seating

**Files:**
- Modify: resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
- Modify: resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

**Interfaces:**
- VirtualShowroom props add shopName, showroomPlacements, and canEditShowroom.
- A drop calls PUT /api/showroom/placements with product_id, from_slot_key, and to_slot_key.
- Seating uses layout.seats and getNearbyShowroomSeat.

- [ ] **Step 1: Add failing integration contracts**

    expect(showroomSource).toContain('showroomPlacements');
    expect(showroomSource).toContain('canEditShowroom');
    expect(showroomSource).toContain('/api/showroom/placements');
    expect(showroomSource).toContain('from_slot_key');
    expect(showroomSource).toContain('to_slot_key');
    expect(showroomSource).toContain("event.key.toLowerCase() === 'e'");
    expect(showroomSource).toContain('E to sit');
    expect(showroomSource).toContain('ShowroomTicTacToe');
    expect(showroomSource).toContain('Stand up');

- [ ] **Step 2: Run the contract test and verify it fails**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

Expected: the new props, endpoint, seating, and game integration strings are absent.

- [ ] **Step 3: Add placement props/state and canonical assignment order**

Import ShowroomPlacement, resolveShowroomPlacements, swapShowroomPlacements, getShowroomLayout, getNearbyShowroomSeat, and ShowroomTicTacToe. Extend VirtualShowroomProps:

    shopName?: string;
    showroomPlacements?: ShowroomPlacement[];
    canEditShowroom?: boolean;

Destructure defaults:

    shopName = '',
    showroomPlacements = [],
    canEditShowroom = false,

Build the initial assignment map from the capacity-limited shoes and layout slots:

    const resolvedAssignments = useMemo(
      () => resolveShowroomPlacements(
        shoes.map((shoe) => ({ id: shoe.id })),
        getShowroomLayout(showroomDisplayCapacity).slots,
        showroomPlacements,
      ),
      [shoes, showroomDisplayCapacity, showroomPlacements],
    );

Keep a state copy for optimistic swaps and a ref for pointer handlers. The existing scene effect may rebuild after a confirmed drop; do not add a second coordinate model.

- [ ] **Step 4: Add owner edit mode and automatic save**

Add refs for placement drag product ID, source slot key, current target slot key, and pending product ID. Add state for edit mode, highlighted target key, fallback selected shoe/slot, and save status. Keep a separate isGameOpen state so closing the XOX panel leaves the visitor seated and offers an Open XOX button.

In edit mode, pointer down raycasts shelf cards to start a drag; pointer move raycasts showroom.slotTargets and highlights the target; pointer up calls one save function when the target differs. The save function applies swapShowroomPlacements optimistically, sends:

    const response = await fetch('/api/showroom/placements', {
      method: 'PUT',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        product_id: productId,
        from_slot_key: sourceSlotKey,
        to_slot_key: targetSlotKey,
      }),
    });

On a successful JSON response, replace state with the returned placements and show saved. On any non-2xx or malformed response, restore the previous assignments and show error. Ignore a second save for the same pending product. Clear refs/highlights on pointer up and cancel.

Render an owner-only Edit showroom toggle in the existing standalone controls. When enabled, render a compact shoe select, shelf select, and Move button that call the same save function for keyboard/touch users. Hide editor controls and target highlights when canEditShowroom is false.

- [ ] **Step 5: Add couch proximity, E seating, and camera freeze**

Keep refs for nearby seat, seated seat, saved walking position, and the current scene layout. In the animation loop, when not focused and not seated, call getNearbyShowroomSeat(cameraPosition.x, cameraPosition.z, layout.seats) and update React state only when its key changes. Render E to sit while a seat is nearby.

Before WASD handling in handleKeyDown:

    if (
      event.key.toLowerCase() === 'e'
      && nearbySeatKeyRef.current
      && seatedSeatKeyRef.current === null
      && focusedShoeIndexRef.current === null
    ) {
      event.preventDefault();
      sitOnSeat(nearbySeatKeyRef.current);
      return;
    }

sitOnSeat copies the selected seat cameraPosition, saves the prior walking position, aims at lookAt, sets seated state, and clears movement keys. The animation loop skips movement/orbit while seated. Stand up restores the saved walking position and clears the seat/game state; Escape uses the same callback. Respect prefers-reduced-motion when moving into the seat.

- [ ] **Step 6: Mount the XOX overlay**

Render ShowroomTicTacToe with open={isGameOpen && seatedSeatKey !== null}, onStandUp={standUp}, and onClose={closeGame}. sitOnSeat sets isGameOpen true. Close game sets it false and leaves the visitor seated; the seated HUD exposes Open XOX to reopen it. Stand up sets it false, leaves the couch, and clears the local game. While seated, hide the walking hint and ignore pointer orbit.

- [ ] **Step 7: Run focused tests and commit**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts

Expected: all showroom contracts and pure behavior tests pass.

Commit:

    git add resources/js/Pages/UserSide/Products/VirtualShowroom.tsx resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts
    git commit -m "feat: add showroom editing and couch interaction"

## Task 7: Render persisted placements without regressions

**Files:**
- Modify: resources/js/Pages/UserSide/Products/VirtualShowroom.tsx
- Modify: resources/js/Pages/UserSide/Products/showroomPlacement.test.ts

**Interfaces:**
- Product cards are created from canonical assignment order; each card userData contains productId, shoeIdx, and slotKey.
- Products that are no longer active/featured or saved rows with invalid slots fall back to remaining deterministic slots.

- [ ] **Step 1: Add the reorder regression assertion**

    it('keeps every eligible product visible after a saved reorder', () => {
      const result = resolveShowroomPlacements(
        [{ id: 10 }, { id: 20 }, { id: 30 }],
        getShowroomLayout(3).slots,
        [{ productId: 30, slotKey: 'slot-0' }],
      );
      expect(result).toEqual([
        { productId: 30, slotKey: 'slot-0' },
        { productId: 10, slotKey: 'slot-1' },
        { productId: 20, slotKey: 'slot-2' },
      ]);
      expect(result.map((item) => item.productId).sort()).toEqual([10, 20, 30]);
    });

- [ ] **Step 2: Run the test and verify the renderer contract**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomPlacement.test.ts

Expected: the mapper regression passes; inspect the card loop for slot-key use before changing it.

- [ ] **Step 3: Map each card to its assignment**

In the scene effect, map layout slots by key and iterate canonical assignments rather than pairing the raw product array with slot-array position. For each assignment, find the shoe by product ID, place the card at that slot, and set:

    card.userData.productId = shoe.id;
    card.userData.shoeIdx = shoeIdx;
    card.userData.slotKey = assignment.slotKey;

Keep pickup animation, hidden-card handling, and 360 frames keyed by shoeIdx. Placement state changes may rebuild the existing scene; never mutate the database from the render loop.

- [ ] **Step 4: Run focused tests and commit**

Run:

    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

Expected: slot counts, ordering, and contracts pass.

Commit:

    git add resources/js/Pages/UserSide/Products/VirtualShowroom.tsx resources/js/Pages/UserSide/Products/showroomPlacement.test.ts
    git commit -m "fix: render saved showroom arrangements"

## Task 8: Verification and browser QA

**Files:**
- No new files. Inspect all changed files and generated output.

- [ ] **Step 1: Run focused backend and frontend checks**

Run:

    php artisan test tests/Feature/ShowroomPlacementTest.php
    pnpm.cmd exec vitest run resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts

Expected: the backend feature file and all focused Vitest files pass.

- [ ] **Step 2: Run the production build**

Run:

    pnpm.cmd build

Expected: Vite completes successfully and writes fresh public/build assets. Do not stage public/build-test; stage only generated public/build files if the repository tracks them as part of the requested delivery.

- [ ] **Step 3: Perform browser verification**

Verify owner branding, owner drag to an empty slot, occupied-slot swap, refresh persistence, linked staff editing, cross-shop denial, customer read-only controls, WASD approach, E seating, one complete XOX round against a legal random bot move, Stand up, portrait containment, and reduced-motion seating.

Record the browser result; do not claim this check passed without running it.

- [ ] **Step 4: Inspect the final diff**

Run:

    git status --short
    git diff --stat
    git diff --check
    git diff

Confirm every changed file is in the file map, no auth work from the main checkout was touched, no debug output or temporary files were added, and public/build-test remains unstaged.

- [ ] **Step 5: Commit only verification-driven corrections**

After all checks pass, inspect git log and commit any remaining correction with:

    git add database/migrations/2026_09_14_000002_create_showroom_product_placements_table.php app/Models/ShowroomProductPlacement.php app/Services/ShowroomPlacementService.php app/Http/Controllers/Api/ShowroomPlacementController.php routes/api.php app/Http/Controllers/UserSide/LandingPageController.php resources/js/Pages/UserSide/Products/showroomLayout.ts resources/js/Pages/UserSide/Products/showroomLayout.test.ts resources/js/Pages/UserSide/Products/showroomPlacement.ts resources/js/Pages/UserSide/Products/showroomPlacement.test.ts resources/js/Pages/UserSide/Products/showroomScene.ts resources/js/Pages/UserSide/Products/VirtualShowroom.tsx resources/js/Pages/UserSide/Products/showroomTicTacToeRules.ts resources/js/Pages/UserSide/Products/showroomTicTacToe.test.ts resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx resources/js/Pages/UserSide/Profile/VirtualShowroomPage.contract.test.ts tests/Feature/ShowroomPlacementTest.php
    git commit -m "feat: complete connected showroom interactions"

Do not push or force-push unless the user explicitly requests it.
