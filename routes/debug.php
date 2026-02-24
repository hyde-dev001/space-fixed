<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

Route::get('/debug-staff-auth', function () {
    $user = Auth::guard('user')->user();
    
    if (!$user) {
        return response()->json(['error' => 'Not authenticated']);
    }
    
    $data = [
        'user_id' => $user->id,
        'user_name' => $user->name,
        'user_email' => $user->email,
        'user_role' => $user->role,
        'user_status' => $user->status,
        'employee_status' => $user->employee ? $user->employee->status->value : 'NO EMPLOYEE RECORD',
        'roles_array' => $user->getRoleNames()->toArray(),
        'permissions_array' => $user->getAllPermissions()->pluck('name')->toArray(),
        'permissions_count' => $user->getAllPermissions()->count(),
        'has_staff_role' => $user->hasRole('Staff'),
        'has_view_job_orders' => $user->hasPermissionTo('view-job-orders'),
        'has_view_products' => $user->hasPermissionTo('view-products'),
        'has_view_pricing' => $user->hasPermissionTo('view-pricing'),
        'has_view_inventory' => $user->hasPermissionTo('view-inventory'),
    ];
    
    return Inertia::render('DebugAuth', $data);
})->middleware(['auth:user']);
