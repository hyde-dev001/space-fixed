<?php

namespace App\Http\Controllers\ShopOwner;

use App\Actions\ShopOwner\ToggleShopOwnerModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShopOwner\UpdateShopOwnerModuleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ShopOwnerModuleController extends Controller
{
    public function update(UpdateShopOwnerModuleRequest $request, ToggleShopOwnerModule $toggle): JsonResponse|RedirectResponse
    {
        try {
            $result = $toggle->handle(
                owner: Auth::guard('shop_owner')->user(),
                moduleKey: (string) $request->validated('module_key'),
                enabled: (bool) $request->validated('enabled'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'The module setting could not be updated. Please try again.'], 500);
            }

            return back()->withErrors(['module' => 'The module setting could not be updated. Please try again.']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Module setting updated.',
                'module_key' => $result['module_key'],
                'enabled' => $result['enabled'],
                'changed' => $result['changed'],
                'states' => $result['states'],
            ]);
        }

        return back()->with('success', 'Module setting updated.');
    }
}
