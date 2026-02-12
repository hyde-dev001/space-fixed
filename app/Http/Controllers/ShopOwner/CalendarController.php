<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /**
     * Display the calendar page.
     *
     * This is a frontend-first approach - no backend logic yet.
     * We'll add database queries and business logic later.
     */
    public function index(): Response
    {
        // For now, we're just rendering the page without any data
        // Later, you can pass data like this:
        // return Inertia::render('ShopOwner/Calendar', [
        //     'events' => Event::all(),
        //     'user' => auth()->user(),
        // ]);
        
        return Inertia::render('ShopOwner/Calendar');
    }
}
