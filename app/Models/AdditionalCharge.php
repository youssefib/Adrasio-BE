<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalCharge extends Model
{
    protected $fillable = [
        'school_id', 'student_profile_id', 'enrollment_id',
        'description', 'amount', 'charge_date', 'status', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'charge_date' => 'date',
            'paid_at'     => 'date',
            'amount'      => 'decimal:2',
        ];
    }

    public function school(): BelongsTo         { return $this->belongsTo(School::class); }
    public function studentProfile(): BelongsTo { return $this->belongsTo(StudentProfile::class); }
    public function enrollment(): BelongsTo     { return $this->belongsTo(CourseEnrollment::class, 'enrollment_id'); }
}
