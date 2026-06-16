<?php

namespace App\Services;

use App\Models\School;

/**
 * Singleton service holding the current tenant school for this request.
 *
 * Bound in AppServiceProvider as a singleton.
 * Set in SetTenantContext middleware via CurrentSchool::set($school).
 *
 * Usage anywhere:
 *   $school = app(CurrentSchool::class)->get();
 *   $school = current_school();   // global helper
 */
class CurrentSchool
{
    private ?School $school = null;

    public function set(?School $school): void
    {
        $this->school = $school;
    }

    public function get(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->id;
    }

    public function required(): School
    {
        abort_if($this->school === null, 403, 'No school context for this request.');
        return $this->school;
    }
}
