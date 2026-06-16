<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['school_id', 'name', 'description', 'color'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(CourseLevel::class)->orderBy('order');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(CourseClass::class);
    }
}
