<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $currentUser = $guard->user();

        if ($currentUser === null) {
            return $next($request);
        }

        // A cached guard or remembered identity must observe persisted revocation
        // and role changes before any protected controller or role check runs.
        // Validate the cached customer's raw identity before Eloquent can cast
        // it to another account's ID. Null uses logoutCurrentDevice below,
        // avoiding remember-token writes against an ambiguous identity.
        $unsafeCustomerId = $currentUser->isCustomer() && ! $currentUser->hasSafeIdentityId();
        $persistedUser = $unsafeCustomerId ? null : User::query()->useWritePdo()->find($currentUser->getAuthIdentifier());

        if ($persistedUser?->canAuthenticate()) {
            $guard->setUser($persistedUser);

            return $next($request);
        }

        if ($persistedUser !== null) {
            $guard->setUser($persistedUser);
            $guard->logout();
        } else {
            $guard->logoutCurrentDevice();
        }

        Cookie::queue(Cookie::forget($guard->getRecallerName()));
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login')->with('error', 'Please sign in to continue.');
    }
}
