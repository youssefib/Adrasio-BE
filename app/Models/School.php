<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class School extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'subscription_plan_id',
        'subscription_tier',
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'logo',
        'timezone',
        'status',
        'school_type',
        'students_access_enabled',
        'teachers_access_enabled',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'trial_ends_at'           => 'datetime',
            'subscription_ends_at'    => 'datetime',
            'students_access_enabled' => 'boolean',
            'teachers_access_enabled' => 'boolean',
        ];
    }

    /** Public URL to the school logo, or null. */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function courseClasses(): HasMany
    {
        return $this->hasMany(CourseClass::class);
    }

    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }

    public function classGroupEnrollments(): HasMany
    {
        return $this->hasMany(ClassGroupEnrollment::class);
    }

    public function teacherCommissions(): HasMany
    {
        return $this->hasMany(TeacherCommission::class);
    }

    public function additionalCharges(): HasMany
    {
        return $this->hasMany(AdditionalCharge::class);
    }

    public function isCourseSchool(): bool
    {
        return $this->school_type === 'course';
    }

    // ── Scoped counts ─────────────────────────────────────────────────────────

    public function studentCount(): int
    {
        return $this->users()->role('student')->count();
    }

    public function teacherCount(): int
    {
        return $this->users()->role('teacher')->count();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isWithinPlanLimits(string $resource): bool
    {
        if (! $this->plan) {
            return false;
        }

        $current = match ($resource) {
            'students' => $this->studentCount(),
            'teachers' => $this->teacherCount(),
            'classes'  => $this->classrooms()->count(),
            default    => 0,
        };

        return $this->plan->withinLimit(rtrim($resource, 's'), $current);
    }
}
