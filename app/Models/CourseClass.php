<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseClass extends Model
{
    protected $fillable = [
        'school_id', 'course_id', 'course_level_id', 'teacher_id', 'room_id',
        'name', 'monthly_fee', 'capacity', 'status',
    ];

    protected function casts(): array
    {
        return ['monthly_fee' => 'decimal:2'];
    }

    public function school(): BelongsTo     { return $this->belongsTo(School::class); }
    public function course(): BelongsTo     { return $this->belongsTo(Course::class); }
    public function level(): BelongsTo      { return $this->belongsTo(CourseLevel::class, 'course_level_id'); }
    public function teacher(): BelongsTo    { return $this->belongsTo(User::class, 'teacher_id'); }
    public function room(): BelongsTo       { return $this->belongsTo(Room::class); }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('day_of_week')->orderBy('start_time');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(TeacherCommission::class);
    }

    /** Active enrollments = status active and no left_at */
    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', 'active')->whereNull('left_at');
    }
}
