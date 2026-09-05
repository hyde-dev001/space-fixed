<?php

declare(strict_types=1);

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class StaffArticlesController extends Controller
{
    public function index(): InertiaResponse|RedirectResponse
    {
        return $this->render();
    }

    public function show(string $slug): InertiaResponse|RedirectResponse
    {
        return $this->render($slug);
    }

    private function render(?string $slug = null): InertiaResponse|RedirectResponse
    {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return Inertia::render('ERP/Articles/Index', [
            'articleSlug' => $slug,
            'articleAudience' => 'staff',
        ]);
    }
}
