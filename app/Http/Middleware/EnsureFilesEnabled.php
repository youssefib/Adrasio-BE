<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the Files module when the school has switched it off. The feature is
 * opt-in (disabled by default) and enabled per school from Settings; when off
 * it is unavailable to everyone, including owners and admins.
 *
 * Runs inside the tenant-scoped group, so $user->school is loaded.
 */
class EnsureFilesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $school = $request->user()?->school;

        if ($school && ! $school->files_access_enabled) {
            abort(403, 'The files feature is disabled for this school.');
        }

        return $next($request);
    }
}
