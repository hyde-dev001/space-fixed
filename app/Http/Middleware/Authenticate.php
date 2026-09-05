<?php

namespace App\Http\Middleware;

use App\Support\Erp\ErpAccessResponder;
use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if ($request instanceof Request
            && app(ErpAccessResponder::class)->isOwnerErpRequest($request)) {
            return route('shop-owner.login.form');
        }

        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
