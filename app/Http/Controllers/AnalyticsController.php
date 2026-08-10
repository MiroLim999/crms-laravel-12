<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Backwards-compatible entry point for bookmarks created before Analytics was
 * consolidated into the role-aware dashboard.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('dashboard', $request->query());
    }
}
