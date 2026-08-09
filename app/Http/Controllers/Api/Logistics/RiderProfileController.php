<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\RiderProfileSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RiderProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = $this->authorizedShop('manage-logistics-riders');
        app(RiderProfileSyncService::class)->syncShop((int) $shop->id);

        return response()->json([
            'riders' => RiderProfile::query()
                ->where('shop_owner_id', $shop->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $this->authorizedShop('manage-logistics-riders');
        $data = $request->validate($this->rules());
        $this->assertLinkedUserBelongsToShop($data, $shop);
        $this->assertRiderTypeAllowed($data['rider_type'], $shop);

        $rider = RiderProfile::create([
            ...$data,
            'shop_owner_id' => $shop->id,
        ]);

        return response()->json(['rider' => $rider], 201);
    }

    public function update(Request $request, RiderProfile $rider): JsonResponse
    {
        $shop = $this->authorizedShop('manage-logistics-riders');
        if ((int) $rider->shop_owner_id !== (int) $shop->id) {
            abort(403);
        }

        $data = $request->validate($this->rules(false));
        $this->assertLinkedUserBelongsToShop($data, $shop, $rider);
        $this->assertRiderTypeAllowed($data['rider_type'] ?? $rider->rider_type, $shop);
        $rider->update($data);

        return response()->json(['rider' => $rider->fresh()]);
    }

    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'rider_type' => [$required, 'in:shop_owner,employee,contractor'],
            'linked_type' => ['nullable', 'string', 'max:255', Rule::in([User::class])],
            'linked_id' => ['nullable', 'integer', 'required_with:linked_type'],
            'name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'availability_status' => ['sometimes', 'in:available,busy,inactive'],
            'active' => ['sometimes', 'boolean'],
            'work_days' => ['sometimes', 'array', 'min:1'],
            'work_days.*' => ['integer', 'distinct', Rule::in(range(1, 7))],
            'leave_dates' => ['sometimes', 'array'],
            'leave_dates.*' => ['date_format:Y-m-d', 'distinct', 'after_or_equal:today'],
            'daily_capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    private function assertLinkedUserBelongsToShop(array $data, ShopOwner $shop, ?RiderProfile $existing = null): void
    {
        $linkedType = array_key_exists('linked_type', $data) ? $data['linked_type'] : $existing?->linked_type;
        $linkedId = array_key_exists('linked_id', $data) ? $data['linked_id'] : $existing?->linked_id;
        $riderType = $data['rider_type'] ?? $existing?->rider_type;

        if ($linkedType === null && $linkedId === null) {
            if ($riderType === 'employee') {
                throw ValidationException::withMessages([
                    'linked_type' => 'Employee riders must be linked to a user account.',
                    'linked_id' => 'Employee riders must be linked to a user account.',
                ]);
            }

            return;
        }

        if ($linkedType !== User::class
            || ! $linkedId
            || ! User::query()->whereKey($linkedId)->where('shop_owner_id', $shop->id)->exists()) {
            throw ValidationException::withMessages([
                'linked_id' => 'Linked rider must belong to this shop.',
            ]);
        }
    }

    private function assertRiderTypeAllowed(string $riderType, ShopOwner $shop): void
    {
        if ($riderType === 'shop_owner' && strtolower((string) $shop->registration_type) !== 'individual') {
            throw ValidationException::withMessages([
                'rider_type' => 'Owner delivery is only allowed for individual shops.',
            ]);
        }
    }

    private function authorizedShop(string $permission): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }

        $user = Auth::guard('user')->user();
        if (!$user instanceof User || !$user->shopOwner || !$user->can($permission)) {
            abort(403);
        }

        return $user->shopOwner;
    }
}
