<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAnalyticsExportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('shopify_sync.analytics_export_token', '');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === ''
            || $providedToken === ''
            || ! hash_equals($configuredToken, $providedToken)) {
            abort(401, 'Invalid analytics export token.');
        }

        return $next($request);
    }
}
