<?php

namespace App\Traits;

use App\Models\School;
use App\Services\CurrentSchool;
use Illuminate\Http\Request;

/**
 * Controller trait — resolves the current tenant school for use in controllers.
 * Complements BelongsToSchool (model trait) and CurrentSchool (service).
 */
trait ScopedToSchool
{
    protected function currentSchool(Request $request): School
    {
        /** @var CurrentSchool $service */
        $service = app(CurrentSchool::class);

        // System admins may operate on any school via X-School-Id header or route binding
        if ($request->user()->isSystemAdmin()) {
            $school = $service->get();

            if (! $school) {
                // Also accept a {school} route model binding
                $routeSchool = $request->route('school');
                if ($routeSchool instanceof School) {
                    return $routeSchool;
                }
                abort(422, 'System admin requests require X-School-Id header or school_id query param.');
            }

            return $school;
        }

        return $service->required();
    }
}
