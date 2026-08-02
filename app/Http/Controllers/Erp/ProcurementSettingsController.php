<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Models\ProcurementSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProcurementSettingsController extends Controller
{
    /**
     * Display procurement settings for the shop owner.
     */
    public function show()
    {
        $shopOwnerId = Auth::user()->shop_owner_id;
        
        $settings = ProcurementSettings::getForShopOwner($shopOwnerId);

        return response()->json($settings?->only([
            'id',
            'shop_owner_id',
            'default_payment_terms',
            'notification_emails',
            'settings_json',
        ]));
    }

    /**
     * Update procurement settings.
     */
    public function update(Request $request)
    {
        $shopOwnerId = Auth::user()->shop_owner_id;

        $validatedData = $request->validate([
            'auto_pr_approval_threshold' => 'prohibited',
            'require_finance_approval' => 'prohibited',
            'default_payment_terms' => 'nullable|string|max:255',
            'notification_emails' => 'nullable|array',
            'notification_emails.*' => 'email',
            'settings_json' => 'nullable|array',
        ]);

        try {
            $settings = ProcurementSettings::updateOrCreate(
                ['shop_owner_id' => $shopOwnerId],
                $validatedData
            );

            return response()->json([
                'message' => 'Procurement settings updated successfully.',
                'settings' => $settings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update procurement settings.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
