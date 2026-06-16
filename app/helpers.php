<?php

use App\Models\School;
use App\Services\CurrentSchool;

if (! function_exists('current_school')) {
    /**
     * Return the current tenant School bound to this request.
     * Returns null when called outside a tenant context (e.g. system admin routes).
     */
    function current_school(): ?School
    {
        return app(CurrentSchool::class)->get();
    }
}
