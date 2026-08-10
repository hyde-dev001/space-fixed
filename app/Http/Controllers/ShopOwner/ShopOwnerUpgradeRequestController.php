<?php

namespace App\Http\Controllers\ShopOwner;

use App\Actions\ShopOwner\SubmitShopOwnerUpgradeRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShopOwner\StoreShopOwnerUpgradeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShopOwnerUpgradeRequestController extends Controller
{
    public function store(StoreShopOwnerUpgradeRequest $request, SubmitShopOwnerUpgradeRequest $submit): JsonResponse|RedirectResponse
    {
        $owner = Auth::guard('shop_owner')->user();

        try {
            $upgradeRequest = $submit->handle($owner, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The business upgrade request could not be submitted. Please try again.',
                ], 500);
            }

            return back()->withErrors([
                'upgrade' => 'The business upgrade request could not be submitted. Please try again.',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Business upgrade request submitted for review.',
                'request' => $upgradeRequest,
            ], 201);
        }

        return back()->with('success', 'Business upgrade request submitted for review.');
    }
}
