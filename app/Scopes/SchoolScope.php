<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // System admins see all tenant data
        if (auth()->check() && auth()->user()->isSystemAdmin()) {
            return;
        }

        // Use the container-bound current school if available (set by SetTenantContext)
        if (app()->bound('current_school')) {
            $school = app('current_school');
            if ($school) {
                $builder->where($model->getTable() . '.school_id', $school->id);
                return;
            }
        }

        // Fallback: derive from the authenticated user
        if (auth()->check() && auth()->user()->school_id) {
            $builder->where($model->getTable() . '.school_id', auth()->user()->school_id);
        }
    }
}
