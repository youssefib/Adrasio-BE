<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePayment extends Model
{
    protected $fillable = [
        'school_id', 'course_enrollment_id', 'month', 'year',
        'amount', 'status', 'notes', 'paid_at', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'month'    => 'integer',
            'year'     => 'integer',
            'amount'   => 'decimal:2',
            'paid_at'  => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class, 'course_enrollment_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
