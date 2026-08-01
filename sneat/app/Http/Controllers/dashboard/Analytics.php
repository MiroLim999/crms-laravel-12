<?php

namespace App\Http\Controllers\dashboard;

use App\Http\Controllers\Controller;

class Analytics extends Controller
{
    public function index()
    {
        return view('content.dashboard.dashboards-analytics');
    }
}
