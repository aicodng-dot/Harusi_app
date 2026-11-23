<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

class WelcomeScreenController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            // The existing login entry point owns the role-to-destination redirect.
            return redirect()->route('login');
        }

        $language = SplashScreenController::language($request);
        $response = response()->view('reference_ui.10_welcome_how_it_works', ['language' => $language]);

        // Reaching Welcome means the splash has been seen; the cookie records only that.
        if ($request->cookie(SplashScreenController::COMPLETION_COOKIE) !== '1') {
            $response->withCookie(Cookie::make(
                SplashScreenController::COMPLETION_COOKIE,
                '1',
                60 * 24 * 365,
                '/',
                config('session.domain'),
                $request->isSecure() || app()->environment('production'),
                true,
                false,
                'lax',
            ));
        }

        return $response;
    }
}
