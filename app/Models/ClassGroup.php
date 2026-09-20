<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassGroup extends Model
{
    protected $fillable = [
        'school_id', 'name', 'monthly_price', 'status',
    ];

    protected function casts(): array
    {
        return ['monthly_price' => 'decimal:2'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Member classes (a class may be in several packs). */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(CourseClass::class, 'class_group_members');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassGroupEnrollment::class);
    }

    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', 'active')->whereNull('left_at');
    }

    /**
     * The share of the pack price attributed to each member class
     * (pack price divided equally across the member classes).
     */
    public function perClassShare(?float $price = null): float
    {
        $count = $this->classes()->count();
        $total = $price ?? (float) $this->monthly_price;

        return $count > 0 ? round($total / $count, 2) : 0.0;
    }
}
