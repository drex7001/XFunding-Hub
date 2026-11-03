<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['active-account' => EnsureActiveAccount::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form submitted against a changed record returns to its page with the reason, not an error page.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if (
                $exception->getStatusCode() !== 409
                || $request->isMethodSafe()
                || $request->expectsJson()
                || $request->is('api/*')
            ) {
                return null;
            }

            return back(fallback: route('dashboard'))
                ->withInput($request->except(['password', 'password_confirmation', 'document', '_token', '_method']))
                ->withErrors([
                    'conflict' => $exception->getMessage() ?: 'This action is no longer available. Refresh the page and try again.',
                ]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
