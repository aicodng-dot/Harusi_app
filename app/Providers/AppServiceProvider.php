<?php

namespace App\Providers;

use App\Exceptions\LegacyAdminReadUnavailable;
use App\Models\User;
use App\Services\LoginIdentifier;
use App\Services\Tenancy\LegacyAdminReadProjection;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('device-login', function (Request $request): array {
            $identifier = $request->input('identifier', $request->input('email'));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('identifier:'.app(LoginIdentifier::class)->rateLimitKey($identifier)),
            ];
        });

        RateLimiter::for('login', function (Request $request): array {
            $identifier = $request->input('identifier', $request->input('email'));

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinute(10)->by('identifier:'.app(LoginIdentifier::class)->rateLimitKey($identifier)),
            ];
        });

        foreach (['password-recovery', 'password-reset'] as $limiter) {
            RateLimiter::for($limiter, function (Request $request) use ($limiter): array {
                $identifier = $limiter === 'password-reset'
                    ? $request->input('email')
                    : $request->input('identifier', $request->input('email'));

                return [
                    Limit::perMinute(10)->by('ip:'.$request->ip()),
                    Limit::perMinute(10)->by('identifier:'.app(LoginIdentifier::class)->rateLimitKey($identifier)),
                ];
            });
        }

        RateLimiter::for('customer-workspace-create', function (Request $request): array {
            $language = in_array($request->user()?->preferred_language, ['en', 'sw'], true)
                ? $request->user()->preferred_language : 'sw';
            $limited = static fn (Request $request, array $headers) => response(
                __('customer.throttled', [], $language), 429, $headers,
            )->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);

            return [
                Limit::perMinute(5)->by('account:'.$request->user()?->getAuthIdentifier())->response($limited),
                Limit::perMinute(20)->by('ip:'.$request->ip())->response($limited),
            ];
        });

        ResetPassword::createUrlUsing(fn ($user, string $token): string => rtrim(config('app.url'), '/')
            .'/reset-password/'.rawurlencode($token).'?'.http_build_query([
                'email' => $user->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986));

        // P4-047: layout values are prepared when the page view is made (before any
        // rendering), unless the page's own read bundle already supplied them.
        View::creator(LegacyAdminReadProjection::LAYOUT_PAGES, function ($view): void {
            if (array_key_exists('adminLayout', $view->getData())) {
                return;
            }
            $actor = auth()->user();
            $view->with('adminLayout', $actor instanceof User
                ? app(LegacyAdminReadProjection::class)->layout($actor, session('selected_event_id'))
                : LegacyAdminReadProjection::emptyLayout());
        });

        // The layout only consumes those frozen values: no SQL, no session reread. An
        // authenticated render without them means a view is missing from LAYOUT_PAGES;
        // it refuses rather than silently hiding the selection and offline-lock banner.
        View::composer('layouts.admin', function ($view): void {
            $layout = $view->getData()['adminLayout'] ?? null;
            if (! is_array($layout)) {
                if (auth()->hasUser()) {
                    throw new LegacyAdminReadUnavailable;
                }
                $layout = LegacyAdminReadProjection::emptyLayout();
            }
            $view->with($layout);
        });
    }
}
