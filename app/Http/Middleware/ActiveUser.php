<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->fresh()?->active) {
            auth()->logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
