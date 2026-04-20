<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalOnly
{
    /**
     * Gail is designed to run on the operator's own machine. This
     * middleware refuses any request that does not originate from a
     * loopback address unless the application is explicitly configured
     * to permit remote access via `gail.allow_remote`.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('gail.allow_remote', false)) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null || ! $this->isLoopback($ip)) {
            abort(403, 'Gail only accepts connections from localhost.');
        }

        return $next($request);
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1'
            || $ip === '::1'
            || str_starts_with($ip, '127.');
    }
}
