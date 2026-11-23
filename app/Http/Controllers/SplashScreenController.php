<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SplashScreenController extends Controller
{
    public const COMPLETION_COOKIE = 'pangaevents_intro';

    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            // Keep the role-to-destination decision in the existing auth flow.
            return redirect()->route('login');
        }

        $language = self::language($request);

        if ($request->cookie(self::COMPLETION_COOKIE) === '1') {
            return redirect()->route('login');
        }

        return view('reference_ui.01_splash_screen', ['language' => $language]);
    }

    /**
     * Shared onboarding language resolver: ?lang= wins and is remembered in the
     * same `login_language` session key used by the login and registration pages.
     */
    public static function language(Request $request): string
    {
        $requested = $request->query('lang');
        if (in_array($requested, User::SUPPORTED_LANGUAGES, true)) {
            $request->session()->put('login_language', $requested);
        }

        $language = $request->session()->get('login_language', 'sw');

        return in_array($language, User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';
    }
}
