<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Validation\ValidationException;

class SubscriptionLimitService
{
    public function __construct(private School $school) {}

    public static function for(School $school): self
    {
        return new self($school);
    }

    /**
     * Get all limits for the school's current tier (config/saas.php).
     * Falls back to the attached subscription plan if available.
     */
    public function limits(): array
    {
        $tier = $this->school->subscription_tier ?? 'trial';
        $configLimits = config("saas.tiers.{$tier}", config('saas.tiers.trial'));

        // Merge with plan limits (plan wins for numeric limits)
        $plan = $this->school->plan;
        if ($plan) {
            $configLimits['max_students'] = $plan->max_students ?? $configLimits['max_students'];
            $configLimits['max_teachers'] = $plan->max_teachers ?? $configLimits['max_teachers'];
            $configLimits['max_classes']  = $plan->max_classes  ?? $configLimits['max_classes'];
        }

        return $configLimits;
    }

    public function limit(string $key): int|null
    {
        return $this->limits()[$key] ?? null;
    }

    /**
     * Throw a validation exception if the resource count would exceed the limit.
     */
    public function enforce(string $resource): void
    {
        $limitKey = "max_{$resource}";
        $limit    = $this->limit($limitKey);

        if ($limit === null) {
            return; // unlimited
        }

        $current = match ($resource) {
            'students' => $this->school->users()->role('student')->count(),
            'teachers' => $this->school->users()->role('teacher')->count(),
            'admins'   => $this->school->users()->role('admin')->count(),
            'classes'  => $this->school->classrooms()->count(),
            default    => 0,
        };

        if ($current >= $limit) {
            throw ValidationException::withMessages([
                $resource => ["Plan limit reached: maximum {$limit} {$resource} allowed on your current tier."],
            ]);
        }
    }

    /**
     * Check storage usage against the plan limit (approximate, in MB).
     */
    public function enforceStorage(int $incomingBytes): void
    {
        $limitMb = $this->limit('max_storage_mb');
        if ($limitMb === null) {
            return;
        }

        $usedBytes = $this->school->files()->sum('size_bytes');
        $usedMb    = $usedBytes / 1024 / 1024;

        if (($usedMb + $incomingBytes / 1024 / 1024) > $limitMb) {
            throw ValidationException::withMessages([
                'file' => ["Storage limit reached: your plan allows {$limitMb} MB total."],
            ]);
        }
    }
}
