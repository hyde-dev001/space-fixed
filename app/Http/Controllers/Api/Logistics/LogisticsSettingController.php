<?php

namespace App\Http\Controllers\Api\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsSetting;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\ShopModuleAccessService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LogisticsSettingController extends Controller
{
    public function __construct(private ShopModuleAccessService $modules) {}

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
        $actor = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();
        abort_unless($actor instanceof Authenticatable, 403);

        $shop = $actor instanceof ShopOwner
            ? $actor
            : ($actor instanceof User && $actor->shop_owner_id
                ? ShopOwner::find($actor->shop_owner_id)
                : null);
        abort_unless($shop, 403);

        if ($actor instanceof User) {
            if (! $actor->can('configure-logistics-settings')) {
                $this->logDenial($actor, $shop, 'settings_admin', 'action_not_allowed');
                abort(403);
            }
        }

        if (! $this->modules->canAccess($shop, 'logistics')) {
            $this->logDenial($actor, $shop, 'settings_admin', 'module_unavailable');
            abort(403);
        }

        return $shop;
    }

    private function logDenial(
        Authenticatable $actor,
        ShopOwner $shop,
        string $action,
        string $reasonCategory,
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
}
