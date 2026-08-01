<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 12 ships a bare base controller. Authorization is central to this
     * app's separation of duties, so $this->authorize() is available everywhere.
     */
    use AuthorizesRequests;
}
