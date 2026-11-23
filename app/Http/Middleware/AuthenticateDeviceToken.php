<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DeviceTokenService;
use App\Support\DeviceApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDeviceToken
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        // Do not change the default web guard or accept a session identity.
        $guard = Auth::guard('sanctum');
        $guard->forgetUser();
        $user = $guard->user();
        $token = $user?->currentAccessToken();
        $fresh = $user instanceof User ? User::query()->find($user->getKey()) : null;

        if (! $token instanceof PersonalAccessToken || ! $token->exists
            || ! $fresh?->canUseDeviceTokens() || ! $fresh->tokens()->whereKey($token->getKey())->exists()) {
            return DeviceApiResponse::error('Unauthenticated.', 401);
        }

        if (! $token->can($ability)) {
            return DeviceApiResponse::error('This action is not authorized.', 403);
        }

        // A scanner who lost event access may still revoke their current token.
        if ($ability !== 'auth:logout' && ! app(DeviceTokenService::class)->hasActiveAssignment($fresh)) {
            return DeviceApiResponse::error('This action is not authorized.', 403);
        }

        $fresh->withAccessToken($token);
        $guard->setUser($fresh);
        $request->setUserResolver(fn (?string $requestedGuard = null) => $requestedGuard === 'web'
            ? Auth::guard('web')->user() : $fresh);

        return $next($request);
    }
}
