<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'school_id',
        'student_profile_id',
        'course_enrollment_id',
        'class_group_enrollment_id',
        'amount',
        'reason',
        'refunded_at',
        'recorded_by',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'refunded_at' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function classGroupEnrollment(): BelongsTo
    {
        return $this->belongsTo(ClassGroupEnrollment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
