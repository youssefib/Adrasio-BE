<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'school_id',
        'role',
        'name',
        'email',
        'phone',
        'avatar',
        'password',
        'is_active',
        'last_login_at',
        'base_salary',
        'salary_type',
        'salary_variable_rate',
        'salary_rate_is_percentage',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'is_active'            => 'boolean',
            'last_login_at'        => 'datetime',
            'base_salary'               => 'decimal:2',
            'salary_variable_rate'      => 'decimal:2',
            'salary_rate_is_percentage' => 'boolean',
        ];
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function taughtClasses(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    public function enrolledClasses(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'student_classroom', 'student_id')
            ->withPivot('enrolled_at', 'left_at')
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'student_id');
    }

    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(File::class, 'uploaded_by');
    }

    public function studentProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    // ── Boot — keep Spatie roles in sync with the role column ─────────────────

    protected static function booted(): void
    {
        // Whenever the role column changes (or a new user is created),
        // sync the Spatie model_has_roles pivot so helper methods like
        // hasRole() and the permission middleware stay accurate.
        static::saved(function (self $user) {
            if ($user->wasChanged('role') || ! $user->roles()->exists()) {
                $user->syncRoles([$user->role]);
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isSystemAdmin(): bool
    {
        return $this->hasRole('system_admin');
    }

    public function isSchoolOwner(): bool
    {
        return $this->hasRole('school_owner');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isTeacher(): bool
    {
        return $this->hasRole('teacher');
    }

    public function isStudent(): bool
    {
        return $this->hasRole('student');
    }
}
