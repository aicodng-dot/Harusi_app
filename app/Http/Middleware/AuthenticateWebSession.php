<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;

class AuthenticateWebSession extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $guard = $this->auth->guard('web');

        // Programmatic identities (including actingAs) have no browser login key.
        if (! $request->session()->has($guard->getName()) && ! $guard->viaRemember()) {
            return $next($request);
        }

        // Legacy browser sessions must reauthenticate once; never bless an old
        // session with the current password hash after a password change.
        if (! $request->session()->has('password_hash_web') && ! $guard->viaRemember()) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
