<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class AuthenticatePanelUser extends Middleware
{
    /**
     * @param  array<string>  $guards
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();
        $panel = Filament::getCurrentPanel();

        $canAccess = $user instanceof FilamentUser
            ? $user->canAccessPanel($panel)
            : (config('app.env') === 'local');

        if (! $canAccess) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'auth' => 'Your account is not allowed to access this system. Contact an administrator.',
                ]);
        }

        return $next($request);
    }

    protected function redirectTo($request): ?string
    {
        return Filament::getLoginUrl();
    }
}
