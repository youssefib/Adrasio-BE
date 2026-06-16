<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseEnrollment extends Model
{
    protected $fillable = [
        'school_id', 'student_profile_id', 'course_class_id',
        'monthly_fee_override', 'enrolled_at', 'left_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at'          => 'date',
            'left_at'              => 'date',
            'monthly_fee_override' => 'decimal:2',
        ];
    }

    public function school(): BelongsTo          { return $this->belongsTo(School::class); }
    public function studentProfile(): BelongsTo  { return $this->belongsTo(StudentProfile::class); }
    public function courseClass(): BelongsTo     { return $this->belongsTo(CourseClass::class); }

    public function monthlyStatuses(): HasMany
    {
        return $this->hasMany(MonthlyEnrollmentStatus::class, 'enrollment_id');
    }

    public function additionalCharges(): HasMany
    {
        return $this->hasMany(AdditionalCharge::class, 'enrollment_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CoursePayment::class, 'course_enrollment_id');
    }

    /** Effective monthly fee: override or class default */
    public function effectiveFee(): float
    {
        return (float) ($this->monthly_fee_override ?? $this->courseClass->monthly_fee);
    }

    /** Is this enrollment active for a given year/month? */
    public function isActiveForMonth(int $year, int $month): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Check left_at
        if ($this->left_at && $this->left_at->year <= $year && $this->left_at->month < $month) {
            return false;
        }

        // Check enrolled_at
        $enrolledYear  = $this->enrolled_at->year;
        $enrolledMonth = $this->enrolled_at->month;
        if ($enrolledYear > $year || ($enrolledYear === $year && $enrolledMonth > $month)) {
            return false;
        }

        // Check monthly override
        $override = $this->monthlyStatuses()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return ! $override || $override->status === 'active';
    }
}
