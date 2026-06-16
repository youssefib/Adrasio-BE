<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchoolIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isSystemAdmin()) {
            $school = $user->school;
            abort_if(
                ! $school || $school->status === 'suspended' || $school->status === 'cancelled',
                403,
                'School account is inactive.'
            );
        }

        return $next($request);
    }
}
