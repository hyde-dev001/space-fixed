<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureOwnerErpWorkspaceEnabled::class)
    ->prefix('shop-owner/erp')
    ->name('shop-owner.erp.')
    ->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'index'])
            ->name('workspace');
    });
