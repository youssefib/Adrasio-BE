<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks teacher/student portal access when the school has switched it off.
 * Owners and admins are never affected.
 *
 * Runs inside the tenant-scoped group, so $user->school is loaded.
 */
class EnsurePortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user   = $request->user();
        $school = $user?->school;

        if ($school) {
            if ($user->role === 'teacher' && ! $school->teachers_access_enabled) {
                abort(403, 'Teacher access is currently disabled for this school.');
            }
            if ($user->role === 'student' && ! $school->students_access_enabled) {
                abort(403, 'Student access is currently disabled for this school.');
            }
        }

        return $next($request);
    }
}
