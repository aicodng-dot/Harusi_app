<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectScannerToHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure() || $this->isLocalhost($request->getHost())) {
            return $next($request);
        }

        $appUrl = (string) config('app.url');
        if (! str_starts_with($appUrl, 'https://')) {
            return $next($request);
        }

        return redirect()->away(rtrim($appUrl, '/').$request->getRequestUri());
    }

    private function isLocalhost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
    }
}



