<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corrals a user with `must_change_password` into the change-password screen.
 *
 * The seeded `admin` account ships with a well-known password, so the very
 * first login has to end in a rotation before anything else is reachable.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.change', 'password.update', 'logout')) {
            return redirect()
                ->route('password.change')
                ->with('warning', 'Please choose a new password before continuing.');
        }

        return $next($request);
    }
}
