<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\RepairDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RepairAvailabilityController extends Controller
{
    /**
     * Return the day-of-week numbers (0 = Sunday … 6 = Saturday) on which
     * the given shop is CLOSED, based on its operating-hours columns.
     *
     * GET /api/repair/shop-hours?shop_id=1
     * Response: { success: true, closed_day_numbers: [0, 6] }
     */
    public function shopHours(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shop_owners,id'],
        ]);

        /** @var ShopOwner $shop */
        $shop = ShopOwner::findOrFail($validated['shop_id']);

        // JS Date.getDay() mapping: 0 = Sunday … 6 = Saturday
        $dayMap = [
            'sunday'    => 0,
            'monday'    => 1,
            'tuesday'   => 2,
            'wednesday' => 3,
            'thursday'  => 4,
            'friday'    => 5,
            'saturday'  => 6,
        ];

        $closedDayNumbers = [];
        foreach ($dayMap as $day => $num) {
            if (! $shop->isOpenOn($day)) {
                $closedDayNumbers[] = $num;
            }
        }

        return response()->json([
            'success'            => true,
            'shop_id'            => (int) $validated['shop_id'],
            'closed_day_numbers' => $closedDayNumbers,
        ]);
    }

    public function deliveryQuote(Request $request, ShopOwner $shop, RepairDeliveryService $delivery)
    {
        $data = $request->validate(['address_id' => ['required', 'integer']]);
        $address = $request->user('user')?->addresses()->find($data['address_id']);

        if (! $address) {
            throw ValidationException::withMessages([
                'address_id' => 'Choose one of your saved addresses.',
            ]);
        }

        return response()->json($delivery->quote($shop, $address));
    }

}
