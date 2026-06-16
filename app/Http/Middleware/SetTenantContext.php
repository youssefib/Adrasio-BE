<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Services\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant School and binds it to the container.
 *
 * Resolution order:
 *  1. For regular users  → derive from auth()->user()->school_id.
 *  2. For system_admin   → read the X-School-Id header (so they can act on
 *                          behalf of any school). Falls back to ?school_id=.
 *
 * After this middleware runs, any code can access the school via:
 *   current_school()           → global helper
 *   app(CurrentSchool::class)->get()
 *   app('current_school')
 */
class SetTenantContext
{
    public function __construct(private CurrentSchool $currentSchool) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isSystemAdmin()) {
            // System admins optionally pass X-School-Id header or ?school_id= query param
            $id = $request->header('X-School-Id') ?? $request->query('school_id');
            if ($id) {
                $school = School::find($id);
                if ($school) {
                    $this->currentSchool->set($school);
                }
            }
        } else {
            abort_if(! $user->school_id, 403, 'User is not associated with a school.');

            $school = $user->school;
            abort_if(! $school, 403, 'School not found.');

            $this->currentSchool->set($school);
        }

        return $next($request);
    }
}
