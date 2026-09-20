<?php

namespace App\Services;

use App\Models\ClassGroup;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;

/**
 * Single source of truth for course-school money math.
 *
 * A class's monthly revenue = the sum, over its active enrollments, of each
 * enrollment's contribution:
 *   - individual enrollment → its effective fee (override or class fee)
 *   - pack member           → the pack price / number of member classes
 *
 * The class teacher's cut = class revenue × teacher_share_pct.
 *
 * Callers should eager-load `enrollments.groupEnrollment` (and, for the active
 * check, `enrollments.monthlyStatuses`) on the classes they pass in.
 */
class CourseRevenueService
{
    /** @var array<int, array{count:int, price:float}> pack id → member count + price */
    private array $groups;

    public function __construct(int $schoolId)
    {
        $this->groups = ClassGroup::where('school_id', $schoolId)
            ->withCount('classes')
            ->get()
            ->mapWithKeys(fn (ClassGroup $g) => [
                $g->id => ['count' => (int) $g->classes_count, 'price' => (float) $g->monthly_price],
            ])
            ->all();
    }

    /** Revenue a single enrollment contributes to its class (no active check). */
    public function enrollmentRevenue(CourseEnrollment $e): float
    {
        if ($e->class_group_enrollment_id) {
            $ge = $e->groupEnrollment;
            if (! $ge) {
                return 0.0;
            }
            $group = $this->groups[$ge->class_group_id] ?? null;
            if (! $group || $group['count'] === 0) {
                return 0.0;
            }
            $price = $ge->price_override !== null ? (float) $ge->price_override : $group['price'];

            return round($price / $group['count'], 2);
        }

        return $e->effectiveFee();
    }

    /** Total revenue of a class for a month (active enrollments only). */
    public function classIncome(CourseClass $class, int $year, int $month): float
    {
        return (float) $class->enrollments
            ->filter(fn (CourseEnrollment $e) => $e->isActiveForMonth($year, $month))
            ->sum(fn (CourseEnrollment $e) => $this->enrollmentRevenue($e));
    }

    /** The class teacher's share of a given class income. */
    public function teacherShare(CourseClass $class, float $income): float
    {
        return round($income * ((float) $class->teacher_share_pct / 100.0), 2);
    }
}
