<?php

namespace App\Http\Middleware;

use App\BankStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()->fresh(['membership.bank']);
        if (! $user || ! $user->active || $request->session()->get('auth_version') !== $user->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Please sign in again.'], 401) : redirect()->route('login');
        }
        if (! $user->is_platform_admin) {
            abort_unless($user->membership?->active, 403, 'Your bank membership is inactive.');
            abort_if($user->membership->bank->status === BankStatus::Suspended, 403, 'Your bank is suspended.');
        }
        Auth::setUser($user);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
