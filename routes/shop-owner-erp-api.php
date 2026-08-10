<?php

declare(strict_types=1);

use App\Http\Controllers\Erp\WorkspaceController;
use App\Http\Middleware\EnsureOwnerErpWorkspaceEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureOwnerErpWorkspaceEnabled::class)
    ->prefix('api/shop-owner/erp')
    ->name('shop-owner.erp.api.')
    ->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'data'])
            ->name('workspace');
    });
