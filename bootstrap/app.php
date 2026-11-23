<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\DeviceApiSecurity::class);
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp/meta',
        ]);

        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\RedirectScannerToHttps::class,
        );

        $middleware->web(append: [
            \App\Http\Middleware\EnsureActiveAccount::class,
            \App\Http\Middleware\AuthenticateWebSession::class,
        ]);
        // Global so redirects and error pages carry the headers too; never overrides route values.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\EnsureActiveAccount::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\EnsureActiveAccount::class,
            \App\Http\Middleware\AuthenticateWebSession::class,
        );

        $middleware->alias([
            'device.token' => \App\Http\Middleware\AuthenticateDeviceToken::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'admin.event' => \App\Http\Middleware\EnsureAdminEventSelected::class,
            'admin.private' => \App\Http\Middleware\PrivateNoStoreResponse::class,
            'scanner.https' => \App\Http\Middleware\RedirectScannerToHttps::class,
            'offline.admission' => \App\Http\Middleware\EnsureLaravelAdmissionAllowed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, Request $request) {
            return $request->is('api/v1/*')
                ? \App\Support\DeviceApiResponse::exception($exception)
                : null;
        });
        $exceptions->dontFlash(['token']);

        $exceptions->respond(function ($response, $exception, Request $request) {
            if ($request->is('api/v1/*')) {
                return $response;
            }

            if ($response->getStatusCode() === 429 && $request->routeIs('login.attempt') && ! $request->expectsJson()) {
                // Keep the 429 status but show the login form with localized retry guidance.
                return app(\App\Http\Controllers\AuthController::class)
                    ->throttled($request, $response->headers->get('Retry-After'));
            }

            if ($response->getStatusCode() === 429 && $request->routeIs('password.email') && ! $request->expectsJson()) {
                return app(\App\Http\Controllers\PasswordRecoveryController::class)
                    ->throttled($request, $response->headers->get('Retry-After'));
            }

            if ($response->getStatusCode() === 429 && $request->routeIs('password.update') && ! $request->expectsJson()) {
                return app(\App\Http\Controllers\PasswordRecoveryController::class)
                    ->resetThrottled($request, $response->headers->get('Retry-After'));
            }

            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            if ($request->routeIs('password.update') && ! $request->expectsJson()) {
                // Back to the same emailed link (never a new token); passwords are not flashed.
                $language = $request->session()->get('login_language', 'sw');
                $language = in_array($language, \App\Models\User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';
                $token = $request->input('token');
                $email = $request->input('email');

                if (! is_string($token) || $token === '' || strlen($token) > 255 || ! is_string($email) || strlen($email) > 255) {
                    return redirect()->route('password.request');
                }

                return redirect()
                    ->route('password.reset', ['token' => $token, 'email' => $email])
                    ->withErrors(['form' => __('password_reset.session_expired', [], $language)]);
            }

            if ($request->routeIs('password.email') && ! $request->expectsJson()) {
                // Return to the recovery form (not Login) with the non-secret identifier.
                $language = $request->session()->get('login_language', 'sw');
                $language = in_array($language, \App\Models\User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';
                $identifier = $request->input('identifier');

                return redirect()
                    ->route('password.request')
                    ->withInput(['identifier' => is_string($identifier) && strlen($identifier) <= 255 ? $identifier : ''])
                    ->withErrors(['form' => __('password_recovery.session_expired', [], $language)]);
            }

            $message = 'Your session expired. Refresh the page and try that action again.';

            if ($request->routeIs('register.store') && ! $request->expectsJson()) {
                // Keep the visitor on the form with non-secret input; passwords are never flashed.
                $language = $request->session()->get('login_language', 'sw');
                $language = in_array($language, \App\Models\User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';

                return redirect()
                    ->route('register')
                    ->withInput($request->only(['name', 'phone_number', 'email', 'preferred_language']))
                    ->withErrors(['form' => __('register.session_expired', [], $language)]);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 419);
            }

            if ($request->is('admin/*')) {
                return redirect()
                    ->back(302, [], route('login'))
                    ->with('error', $message);
            }

            return redirect()
                ->route('login')
                ->with('error', $message);
        });
    })->create();
