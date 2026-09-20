<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassGroupEnrollment extends Model
{
    protected $fillable = [
        'school_id', 'class_group_id', 'student_profile_id',
        'price_override', 'enrolled_at', 'left_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at'    => 'date',
            'left_at'        => 'date',
            'price_override' => 'decimal:2',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClassGroupPayment::class);
    }

    /** Member-class enrollments created by this pack subscription. */
    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'class_group_enrollment_id');
    }

    /** The price actually charged for this subscription (override or pack price). */
    public function effectivePrice(): float
    {
        return (float) ($this->price_override ?? $this->group->monthly_price);
    }
}
