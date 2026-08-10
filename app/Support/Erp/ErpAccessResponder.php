<?php

declare(strict_types=1);

namespace App\Support\Erp;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ErpAccessResponder
{

    /**
     * @param  array<int, string>  $moduleKeys
     */
    public function deny(
        Request $request,
        string $code,
        array $moduleKeys = [],
        ?string $message = null,
        int $status = 403,
    ): Response {
        $publicCode = $this->publicCode($code, $request);
        $moduleKeys = $this->moduleKeys($moduleKeys);
        $message ??= $this->message($publicCode);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'code' => $publicCode,
                'error' => $publicCode,
                'message' => $message,
                'module_keys' => $moduleKeys,
            ], $status);
        }

        return redirect($this->browserTarget($request, $publicCode))
            ->with('error', $message);
    }

    public function isOwnerErpRequest(Request $request): bool
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return str_starts_with($routeName, 'shop-owner.erp.')
            || str_starts_with($routeName, 'shop_owner.erp.');
    }

    private function publicCode(string $code, Request $request): string
    {
        if ($this->isOwnerErpRequest($request) && $code === 'UNKNOWN_MODULE') {
            return 'ERP_ROUTE_NOT_ALLOWED';
        }

        return $code;
    }

    /**
     * @param  array<int, string>  $moduleKeys
     * @return array<int, string>
     */
    private function moduleKeys(array $moduleKeys): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $moduleKeys),
            static fn (string $key): bool => $key !== '',
        ));
    }

    private function message(string $code): string
    {
        return match ($code) {
            'ERP_AUTH_REQUIRED' => 'Authentication is required for this ERP route.',
            'OWNER_ERP_ACCOUNT_INELIGIBLE' => 'This shop owner account is not eligible for the ERP workspace.',
            'ERP_ROUTE_NOT_ALLOWED' => 'This ERP route is not available for this account.',
            'MODULE_STATE_MISSING' => 'This ERP module has not been initialized for the shop.',
            'MODULE_DISABLED' => 'This ERP module is disabled for the shop.',
            'MODULE_INELIGIBLE' => 'This shop is not eligible for the requested ERP module.',
            default => 'This ERP request is not available.',
        };
    }

    private function browserTarget(Request $request, string $code): string
    {
        if ($code === 'ERP_AUTH_REQUIRED') {
            return route('shop-owner.login.form');
        }

        if ($this->isOwnerErpRequest($request)) {
            if ($code === 'OWNER_ERP_ACCOUNT_INELIGIBLE' && route()->has('shop-owner.pending-approval')) {
                return route('shop-owner.pending-approval');
            }

            if (route()->has('shop-owner.erp.workspace')) {
                return route('shop-owner.erp.workspace');
            }

            if (route()->has('shop-owner.settings')) {
                return route('shop-owner.settings');
            }
        }

        return url('/erp/staff/dashboard');
    }
}
