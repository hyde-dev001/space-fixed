<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LogisticsSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $shop = $this->shop();

        return response()->json(['settings' => LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id])]);
    }

    public function update(Request $request): JsonResponse
    {
        $shop = $this->shop();
        $data = $request->validate([
            'operating_days' => ['required', 'array', 'min:1'],
            'operating_days.*' => ['integer', Rule::in(range(1, 7)), 'distinct'],
            'cutoff_time' => ['required', 'date_format:H:i'],
            'blackout_dates' => ['present', 'array'],
            'blackout_dates.*' => ['date_format:Y-m-d', 'after_or_equal:today', 'distinct'],
            'lead_time_days' => ['required', 'integer', 'min:0'],
            'morning_start' => ['required', 'date_format:H:i'],
            'morning_end' => ['required', 'date_format:H:i', 'after:morning_start'],
            'afternoon_start' => ['required', 'date_format:H:i', 'after:morning_end'],
            'afternoon_end' => ['required', 'date_format:H:i', 'after:afternoon_start'],
            'coverage_radius_km' => ['required', 'numeric', 'gt:0'],
            'arrival_radius_m' => ['required', 'integer', 'between:50,500'],
            'daily_rider_capacity' => ['required', 'integer', 'min:1'],
            'max_delivery_attempts' => ['required', 'integer', 'min:1'],
        ]);

        $settings = DB::transaction(function () use ($shop, $data) {
            ShopOwner::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $settings = LogisticsSetting::firstOrCreate(['shop_owner_id' => $shop->id]);
            $settings->update($data);

            return $settings->fresh();
        });

        return response()->json(['settings' => $settings]);
    }

    private function shop(): ShopOwner
    {
        if ($shop = Auth::guard('shop_owner')->user()) {
            return $shop;
        }
        $user = Auth::guard('user')->user();
        abort_unless($user?->shop_owner_id && $user->can('configure-logistics-settings'), 403);

        return ShopOwner::findOrFail($user->shop_owner_id);
    }
}
