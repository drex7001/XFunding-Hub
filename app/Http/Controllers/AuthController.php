<?php

namespace App\Http\Controllers;

use App\Actions\RecordAuditEvent;
use App\BankStatus;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request, RecordAuditEvent $audit): RedirectResponse
    {
        $credentials = ['email' => Str::lower($request->validated('email')), 'password' => $request->validated('password'), 'active' => true];
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'These credentials are not available. Check your email and password.']);
        }
        $user = $request->user()->load('membership.bank');
        if (! $user->is_platform_admin && (! $user->membership?->active || $user->membership->bank->status === BankStatus::Suspended)) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'These credentials are not available. Check your email and password.']);
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);
        $audit->handle($user, 'account.signed_in', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgotForm(): View
    {
        return view('auth.forgot-password');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return back()->with('status', 'If this account exists, a password reset link has been sent.');
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function reset(Request $request, RecordAuditEvent $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);
        $status = Password::reset($data, function (User $user, string $password) use ($audit): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
            $audit->handle($user, 'account.password_reset', $user);
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Password updated. Please sign in again.');
    }
}
