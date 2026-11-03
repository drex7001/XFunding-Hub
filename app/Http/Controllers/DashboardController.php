<?php

namespace App\Http\Controllers;

use App\Actions\BuildWorkQueue;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BuildWorkQueue $queue): View
    {
        return view('home', ['queue' => $queue->for($request->user())]);
    }
}
