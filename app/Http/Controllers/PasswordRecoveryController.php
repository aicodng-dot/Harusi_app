<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Services\LoginIdentifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class PasswordRecoveryController extends Controller
{
    public const REQUEST_STATUS = 'If a matching account can be recovered, a password reset link will be sent to its registered email.';

    public const INVALID_RESET = 'This password reset link is invalid or expired. Request a new link and try again.';

    public function request(Request $request): Response
    {
        return $this->form('reference_ui.11_forgot_password', [
            'language' => SplashScreenController::language($request),
        ]);
    }

    public function email(Request $request, LoginIdentifier $identifiers): RedirectResponse|JsonResponse
    {
        $identifier = $request->input('identifier', $request->input('email'));
        $identifier = is_string($identifier) && strlen($identifier) <= 255 ? $identifier : null;

        // Browser form only: format feedback (never account existence). JSON clients
        // keep the uniform response for every input, malformed ones included.
        if (! $request->expectsJson() && ($identifier === null || $identifiers->resolve($identifier) === null)) {
            $language = SplashScreenController::language($request);
            $key = $identifier === null || trim($identifier) === '' ? 'identifier_required' : 'identifier_invalid';

            return redirect()->route('password.request')
                ->withInput(['identifier' => (string) $identifier])
                ->withErrors(['identifier' => __('password_recovery.'.$key, [], $language)]);
        }

        // Every identifier follows this one-use, after-response path. No worker
        // is required; delivery is best effort if the PHP process is interrupted.
        defer(fn () => (new SendPasswordResetLink($identifier))->handle());

        return $request->expectsJson()
            ? response()->json(['message' => self::REQUEST_STATUS])
            : redirect()->route('password.request')
                ->withInput(['identifier' => $identifier])
                ->with('status', self::REQUEST_STATUS);
    }

    /**
     * Throttled browser request: keep the 429 status but show the recovery form
     * with localized retry guidance instead of the bare error page.
     */
    public function throttled(Request $request, ?string $retryAfter): Response
    {
        $language = SplashScreenController::language($request);
        $seconds = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;
        $identifier = $request->input('identifier', $request->input('email'));

        return $this->form('reference_ui.11_forgot_password', [
            'language' => $language,
            'throttleMessage' => $seconds === null
                ? __('password_recovery.throttled_generic', [], $language)
                : __('password_recovery.throttled', ['seconds' => $seconds], $language),
            'retainedIdentifier' => is_string($identifier) && strlen($identifier) <= 255 ? $identifier : '',
        ])->setStatusCode(429);
    }

    public function reset(Request $request, string $token): Response
    {
        $email = $request->query('email');
        $email = is_string($email) && strlen($email) <= 255 ? $email : '';

        return $this->resetForm($request, $token, $email);
    }

    /**
     * The reset screen. Link validity is read through the broker against an
     * eligible account only; a bad link renders one generic invalid state.
     */
    public function resetForm(Request $request, string $token, string $email, array $data = []): Response
    {
        $user = $email === '' || strlen($token) > 255 ? null : User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SCANNER, User::ROLE_CUSTOMER])
            ->first();
        $broker = Password::broker('users');

        return $this->form('reference_ui.reset_password', $data + [
            'token' => $token,
            'email' => $email,
            'language' => SplashScreenController::language($request),
            'linkValid' => $user !== null && $broker->tokenExists($user, $token),
            'expireMinutes' => (int) config('auth.passwords.users.expire', 60),
        ]);
    }

    /**
     * Throttled browser reset: keep the 429 status but show the reset screen
     * with localized retry guidance.
     */
    public function resetThrottled(Request $request, ?string $retryAfter): Response
    {
        $language = SplashScreenController::language($request);
        $seconds = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;
        $token = $request->input('token');
        $email = $request->input('email');

        return $this->resetForm(
            $request,
            is_string($token) ? $token : '',
            is_string($email) && strlen($email) <= 255 ? $email : '',
            ['throttleMessage' => $seconds === null
                ? __('password_reset.throttled_generic', [], $language)
                : __('password_reset.throttled', ['seconds' => $seconds], $language)],
        )->setStatusCode(429);
    }

    public function update(Request $request): RedirectResponse
    {
        $language = SplashScreenController::language($request);
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'password.required' => __('password_reset.password_required', [], $language),
            'password.confirmed' => __('password_reset.password_mismatch', [], $language),
            'password.*' => __('password_reset.password_min', [], $language),
        ]);

        $broker = Password::broker('users');
        $connection = $broker->getRepository()->getConnection();

        try {
            $status = $this->performReset($broker, $connection, $validated);
        } catch (QueryException $exception) {
            // Never report the exception itself: its bindings include the new hash.
            Log::error('Password reset unavailable.', ['exception_type' => $exception::class]);

            throw ValidationException::withMessages([
                'form' => __('password_reset.unexpected_error', [], $language),
            ]);
        }

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => self::INVALID_RESET]);
        }

        // Password reset never authenticates the browser. Existing sessions on
        // other devices are rejected by their password marker on next access.
        Auth::guard('web')->logoutCurrentDevice();
        Cookie::queue(Cookie::forget(Auth::guard('web')->getRecallerName()));
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Keep only the visitor's language choice across the fresh session.
        $request->session()->put('login_language', $language);

        return redirect()->route('login')->with('status', __('password_reset.success', [], $language));
    }

    private function performReset(PasswordBroker $broker, ConnectionInterface $connection, array $validated): string
    {
        // Both contenders serialize on the user and token rows. Token validation,
        // password replacement and token deletion share the same transaction.
        return $connection->transaction(function () use ($broker, $connection, $validated): string {
            User::on($connection->getName())->where('email', $validated['email'])->lockForUpdate()->first();
            $connection->table(config('auth.passwords.users.table'))
                ->where('email', $validated['email'])->lockForUpdate()->first();

            return $broker->reset($validated + [
                'is_active' => true,
                'role' => [User::ROLE_ADMIN, User::ROLE_SCANNER, User::ROLE_CUSTOMER],
            ], function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            });
        });
    }

    private function form(string $view, array $data = []): Response
    {
        return response()->view($view, $data)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
