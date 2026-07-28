<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->is_platform_admin ? redirect()->route('operations') : redirect()->route('banks.show', $request->user()->membership->bank_id);
    }
}
