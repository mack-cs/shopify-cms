<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventTwoFactorDisable
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('delete')
            && $request->is('user/two-factor-authentication')
        ) {
            abort(403, 'Two-factor authentication cannot be disabled.');
        }

        return $next($request);
    }
}
