<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\ShopOwnerSetupState;
use App\Services\ShopOwnerSetupPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SetupGuideController extends Controller
{
    public function show(ShopOwnerSetupPlan $plan): JsonResponse
    {
        $owner = Auth::guard('shop_owner')->user();
        $state = ShopOwnerSetupState::query()->where('shop_owner_id', $owner->id)->first();

        $guide = $plan->for($owner);
        foreach ($guide['tasks'] as &$task) {
            if (in_array($task['key'], ['articles', 'customer_preview'], true)
                && ($state?->tutorials[$task['key']]['status'] ?? null) === 'completed') {
                $task['status'] = 'complete';
            }
        }
        unset($task);

        return response()->json($guide + [
            'tutorials' => $state?->tutorials ?? (object) [],
            'welcome_seen' => $state?->welcome_seen_at !== null,
        ]);
    }

    public function progress(Request $request, ShopOwnerSetupPlan $plan): JsonResponse
    {
        $owner = Auth::guard('shop_owner')->user();
        $keys = array_column($plan->for($owner)['tasks'], 'key');
        $data = $request->validate([
            'task_key' => ['required', 'string', Rule::in($keys)],
            'status' => ['required', Rule::in(['not_started', 'in_progress', 'completed', 'skipped'])],
            'step' => ['required', 'integer', 'min:0', 'max:20'],
            'route' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string', 'max:100'],
        ]);
        $state = ShopOwnerSetupState::query()->firstOrCreate(['shop_owner_id' => $owner->id]);
        $tutorials = $state->tutorials ?? [];
        $tutorials[$data['task_key']] = [
            'status' => $data['status'],
            'step' => $data['step'],
            'route' => $data['route'] ?? null,
            'target' => $data['target'] ?? null,
        ];
        $state->update(['tutorials' => $tutorials]);

        return response()->json(['tutorials' => $tutorials]);
    }

    public function dismissWelcome(): JsonResponse
    {
        $owner = Auth::guard('shop_owner')->user();
        ShopOwnerSetupState::query()->updateOrCreate(
            ['shop_owner_id' => $owner->id],
            ['welcome_seen_at' => now()],
        );

        return response()->json(['welcome_seen' => true]);
    }
}
