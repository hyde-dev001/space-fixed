<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Display the dashboard home page.
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard/Home');
    }
}
