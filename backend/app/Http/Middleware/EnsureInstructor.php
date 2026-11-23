<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstructor
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'instructor') {
            abort(403, 'This area is for instructors only.');
        }

        return $next($request);
    }
}
