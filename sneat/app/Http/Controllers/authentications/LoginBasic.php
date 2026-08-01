<?php

namespace App\Http\Controllers\authentications;

use App\Http\Controllers\Controller;

class LoginBasic extends Controller
{
    public function index()
    {
        return view('content.authentications.auth-login-basic');
    }
}
