<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->fresh()?->must_change_password && ! $request->routeIs('password.change', 'password.change.update', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Change your temporary password before continuing.', 'redirect' => route('password.change')], 423);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
