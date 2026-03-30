<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs('filament.*.auth.logout')) {
            return $next($request);
        }

        return redirect()
            ->route('two-factor.show')
            ->with('status', 'Two-factor authentication is required before you can access the admin panel.');
    }
}
