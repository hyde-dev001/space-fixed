<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class FlaggedAccountsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('superAdmin/FlaggedAccounts');
    }
}
