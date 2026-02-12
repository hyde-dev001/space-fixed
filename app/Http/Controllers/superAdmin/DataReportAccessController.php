<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DataReportAccessController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('superAdmin/DataReportAccess');
    }
}
