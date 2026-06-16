<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate based on the `role` column — single source of truth.
 * Usage: middleware(['require_role:school_owner,admin'])
 *
 * Replaces Spatie's `role:` middleware so routes are never blocked
 * when the model_has_roles pivot is out of sync with the role column.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        // Try all guards to find the authenticated user
        $user = $request->user()
            ?? auth('sanctum')->user()
            ?? auth()->user();

        // Normalise: Laravel passes comma-separated args, but routes use pipe-separated strings.
        // Accept both: "role:school_owner,admin" and "role:school_owner|admin"
        $allowed = [];
        foreach ($args as $arg) {
            foreach (preg_split('/[|,]/', $arg) as $r) {
                $r = trim($r);
                if ($r !== '') $allowed[] = $r;
            }
        }

        if (! $user || ! in_array($user->role, $allowed, true)) {
            abort(403, 'User does not have the required role.');
        }

        return $next($request);
    }
}
