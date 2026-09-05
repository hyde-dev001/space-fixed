<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class ArticlesController
{
    /**
     * @var array<int, string>
     */
    private const EMPLOYEE_AUDIENCES = [
        'manager',
        'finance',
        'hr',
        'crm',
        'cashier',
        'repairer',
        'inventory',
        'procurement',
        'logistics-dispatcher',
        'shop-owner',
    ];

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        return $this->render($request);
    }

    public function show(Request $request, string $slug): InertiaResponse|RedirectResponse
    {
        return $this->render($request, $slug);
    }

    private function render(Request $request, ?string $slug = null): InertiaResponse|RedirectResponse
    {
        $audience = (string) $request->route('articleAudience');

        abort_unless(in_array($audience, self::EMPLOYEE_AUDIENCES, true), 404);

        if ($audience !== 'shop-owner' && Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return Inertia::render('ERP/Articles/Index', [
            'articleSlug' => $slug,
            'articleAudience' => $audience,
        ]);
    }
}
