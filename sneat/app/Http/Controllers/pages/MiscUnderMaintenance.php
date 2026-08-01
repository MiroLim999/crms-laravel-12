<?php

namespace App\Http\Controllers\pages;

use App\Http\Controllers\Controller;

class MiscUnderMaintenance extends Controller
{
    public function index()
    {
        return view('content.pages.pages-misc-under-maintenance');
    }
}
