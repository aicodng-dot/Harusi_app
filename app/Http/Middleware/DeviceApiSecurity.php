<?php

namespace App\Http\Middleware;

use App\Support\DeviceApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceApiSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*')) {
            return $next($request);
        }

        $request->headers->set('Accept', 'application/json');

        if (! $request->isSecure()) {
            return DeviceApiResponse::error('HTTPS is required.', 426);
        }

        $response = $next($request);
        $response->headers->add(DeviceApiResponse::headers());

        return $response;
    }
}
