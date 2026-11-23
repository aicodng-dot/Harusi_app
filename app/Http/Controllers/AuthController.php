<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LoginIdentifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect($this->redirectPathFor(Auth::user()));
        }

        [$title, $description] = match ($request->route()?->getName()) {
            'admin.login' => ['Platform Administration', 'Sign in with your Kadify administrator account.'],
            'scanner.login' => ['Scan Guests', 'Sign in with your Kadify scanner account.'],
            default => ['Sign in to Kadify', 'Use your Kadify account to continue.'],
        };

        return view('reference_ui.03_login', [
            'loginTitle' => $title,
            'loginDescription' => $description,
            'language' => SplashScreenController::language($request),
        ]);
    }

    /**
     * Throttled browser sign-in: keep the 429 status but show the login form with
     * localized retry guidance instead of the bare error page.
     */
    public function throttled(Request $request, ?string $retryAfter): Response
    {
        $language = SplashScreenController::language($request);
        $seconds = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;
        $identifier = $request->input('identifier', $request->input('email'));

        return response()->view('reference_ui.03_login', [
            'loginTitle' => 'Sign in to Kadify',
            'loginDescription' => 'Use your Kadify account to continue.',
            'language' => $language,
            'throttleMessage' => $seconds === null
                ? __('login.throttled_generic', [], $language)
                : __('login.throttled', ['seconds' => $seconds], $language),
            'retainedIdentifier' => is_string($identifier) && strlen($identifier) <= 255 ? $identifier : '',
        ], 429);
    }

    public function login(Request $request, LoginIdentifier $identifiers): RedirectResponse
    {
        $identifierField = $request->exists('identifier') ? 'identifier' : 'email';
        $validated = $request->validate([
            $identifierField => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identity = $identifiers->resolve($validated[$identifierField]);

        $previousUserId = Auth::id();
        $remember = $request->boolean('remember');

        try {
            $authenticated = $identity !== null && Auth::attemptWhen(
                $identity + ['password' => $validated['password']],
                fn (User $user): bool => $user->canAuthenticate(),
                $remember,
            );
        } catch (QueryException $exception) {
            // Storage failure: log it, never echo SQL or credentials, and let the visitor retry.
            report($exception);
            $language = SplashScreenController::language($request);

            throw ValidationException::withMessages([
                'form' => __('login.unexpected_error', [], $language),
            ]);
        }

        if (! $authenticated) {
            throw ValidationException::withMessages([
                $identifierField => 'The provided credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie(
            $request->user()->getAuthPassword(),
        ));

        if ($previousUserId !== $request->user()->getAuthIdentifier()) {
            $request->session()->forget(['selected_event_id', 'selected_workspace_id', 'guest_import']);

            if ($previousUserId !== null) {
                $request->session()->forget('url.intended');
            }
        }

        if (! $remember) {
            // A previous account's persistent cookie must not outlive this login.
            Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
        }

        return redirect($this->authorizedIntendedPath($request));
    }

    public function logout(Request $request): RedirectResponse
    {
        // The visitor's chosen language, else the account preference, else the SW default.
        $language = $request->session()->get('login_language', $request->user()?->preferred_language);
        $language = in_array($language, User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // As after a password reset, only the language choice carries into the fresh session.
        $request->session()->put('login_language', $language);

        return redirect()->route('login');
    }

    private function redirectPathFor(User $user): string
    {
        return match ($user->role) {
            User::ROLE_ADMIN => route('admin.dashboard'),
            User::ROLE_SCANNER => route('scanner.dashboard'),
            User::ROLE_CUSTOMER => route('customer.home'),
            default => route('login'),
        };
    }

    private function authorizedIntendedPath(Request $request): string
    {
        $fallback = $this->redirectPathFor($request->user());
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '' || strlen($intended) > 4096
            || preg_match('/[\x00-\x20\\\\]/', $intended)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $intended)
            || str_starts_with($intended, '//')) {
            return $fallback;
        }

        $parts = parse_url($intended);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $origin = parse_url($request->getSchemeAndHttpHost());
            $scheme = strtolower($parts['scheme'] ?? '');
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $originPort = $origin['port'] ?? ($origin['scheme'] === 'https' ? 443 : 80);

            if (! in_array($scheme, ['http', 'https'], true)
                || $scheme !== $origin['scheme']
                || strtolower($parts['host'] ?? '') !== strtolower($origin['host'])
                || $port !== $originPort) {
                return $fallback;
            }
        } elseif (! str_starts_with($intended, '/')) {
            return $fallback;
        }

        $path = $parts['path'] ?? '/';
        $prefix = match ($request->user()->role) {
            User::ROLE_ADMIN => '/admin',
            User::ROLE_SCANNER => '/scanner',
            User::ROLE_CUSTOMER => '/customer',
            default => null,
        };

        // Existing protected route segments are plain text IDs/tokens. Reject
        // encoded or traversing paths rather than relying on browser normalization.
        if ($prefix === null || ($path !== $prefix && ! str_starts_with($path, $prefix.'/'))
            || str_contains($path, '%')
            || str_contains($path, '//')
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) {
            return $fallback;
        }

        return $intended;
    }
}
