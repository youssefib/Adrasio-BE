<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherCommission extends Model
{
    protected $fillable = [
        'school_id', 'teacher_id', 'course_class_id',
        'commission_type', 'amount', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to'   => 'date',
            'amount'         => 'decimal:2',
        ];
    }

    public function school(): BelongsTo      { return $this->belongsTo(School::class); }
    public function teacher(): BelongsTo     { return $this->belongsTo(User::class, 'teacher_id'); }
    public function courseClass(): BelongsTo { return $this->belongsTo(CourseClass::class); }

    /** Is this commission rule active on the given date? */
    public function isActiveOn(string $date): bool
    {
        $d = \Carbon\Carbon::parse($date);
        if ($d->lt($this->effective_from)) return false;
        if ($this->effective_to && $d->gt($this->effective_to)) return false;
        return true;
    }
}
