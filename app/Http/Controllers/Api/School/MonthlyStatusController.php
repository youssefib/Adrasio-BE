<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\MonthlyEnrollmentStatus;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthlyStatusController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /school/course/classes/{class}/monthly-status?year=YYYY&month=MM
     *
     * Returns all enrollments for the class with their status for the given month.
     */
    public function forClass(Request $request, CourseClass $courseClass): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($courseClass->school_id !== $school->id, 403);

        $year  = (int) ($request->year  ?? now()->year);
        $month = (int) ($request->month ?? now()->month);

        $enrollments = $courseClass->enrollments()
            ->with(['studentProfile.user', 'monthlyStatuses' => fn ($q) =>
                $q->where('year', $year)->where('month', $month)
            ])
            ->get();

        $result = $enrollments->map(function (CourseEnrollment $e) use ($year, $month) {
            $override = $e->monthlyStatuses->first();
            return [
                'enrollment_id'      => $e->id,
                'student_profile_id' => $e->student_profile_id,
                'student_name'       => $e->studentProfile->user->name ?? '—',
                'enrollment_status'  => $e->status,
                'monthly_status'     => $override ? $override->status : ($e->status === 'active' ? 'active' : 'inactive'),
                'monthly_status_id'  => $override?->id,
                'notes'              => $override?->notes,
                'effective_fee'      => $e->effectiveFee(),
            ];
        });

        return response()->json([
            'year'        => $year,
            'month'       => $month,
            'class_id'    => $courseClass->id,
            'class_name'  => $courseClass->name,
            'enrollments' => $result,
            'active_count'=> $result->where('monthly_status', 'active')->count(),
        ]);
    }

    /**
     * POST /school/course/enrollments/{enrollment}/monthly-status
     *
     * Upsert the status for a specific month.
     */
    public function upsert(Request $request, CourseEnrollment $enrollment): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($enrollment->school_id !== $school->id, 403);

        $data = $request->validate([
            'year'   => 'required|integer|min:2000|max:2100',
            'month'  => 'required|integer|between:1,12',
            'status' => 'required|in:active,inactive',
            'notes'  => 'nullable|string|max:500',
        ]);

        $status = MonthlyEnrollmentStatus::updateOrCreate(
            ['enrollment_id' => $enrollment->id, 'year' => $data['year'], 'month' => $data['month']],
            ['status' => $data['status'], 'notes' => $data['notes'] ?? null]
        );

        return response()->json($status);
    }

    /**
     * DELETE /school/course/monthly-status/{status}
     * Removes the override — student reverts to enrollment default.
     */
    public function destroy(Request $request, MonthlyEnrollmentStatus $monthlyStatus): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($monthlyStatus->enrollment->school_id !== $school->id, 403);

        $monthlyStatus->delete();

        return response()->json(['message' => 'Override removed.']);
    }
}
