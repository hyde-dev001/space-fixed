<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\RiderProfile;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RiderProfileController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = $this->authorizedShop('manage-logistics-riders');

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

        if (($data['rider_type'] ?? null) === 'shop_owner' && strtolower((string) $shop->registration_type) !== 'individual') {
            abort(422, 'Owner delivery is only allowed for individual shops.');
        }

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

        $rider->update($request->validate($this->rules(false)));

        return response()->json(['rider' => $rider->fresh()]);
    }

    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'rider_type' => [$required, 'in:shop_owner,employee,contractor'],
            'linked_type' => ['nullable', 'string', 'max:255'],
            'linked_id' => ['nullable', 'integer'],
            'name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'availability_status' => ['sometimes', 'in:available,busy,inactive'],
            'active' => ['sometimes', 'boolean'],
        ];
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
