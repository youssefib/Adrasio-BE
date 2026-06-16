<?php

namespace App\Traits;

use App\Models\School;
use App\Scopes\SchoolScope;

/**
 * Apply to any tenant-scoped Eloquent model.
 *
 * 1. Adds a global scope that filters queries by school_id.
 *    System admins bypass the scope entirely.
 * 2. Auto-sets school_id on model creation from the container binding.
 *
 * Usage:
 *   class Grade extends Model {
 *       use BelongsToSchool;
 *   }
 *
 * The scope is bypassed with: Grade::withoutGlobalScope(SchoolScope::class)->get();
 */
trait BelongsToSchool
{
    protected static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope());

        static::creating(function (self $model): void {
            if (empty($model->school_id)) {
                $school = app()->bound('current_school') ? app('current_school') : null;

                if ($school instanceof School) {
                    $model->school_id = $school->id;
                } elseif (auth()->check() && auth()->user()->school_id) {
                    $model->school_id = auth()->user()->school_id;
                }
            }
        });
    }
}
