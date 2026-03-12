<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class EcommerceController extends Controller
{
    /**
     * Display the ecommerce dashboard page.
     */
    public function index(): Response
    {
        return Inertia::render('ShopOwner/Ecommerce');
    }
}
