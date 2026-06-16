<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseEnrollmentController extends Controller
{
    use ScopedToSchool;

    /** GET /school/course/enrollments?class_id=&student_profile_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $enrollments = $school->courseEnrollments()
            ->with(['studentProfile.user', 'courseClass.course'])
            ->when($request->course_class_id, fn ($q) => $q->where('course_class_id', $request->course_class_id))
            ->when($request->student_profile_id, fn ($q) => $q->where('student_profile_id', $request->student_profile_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('enrolled_at')
            ->paginate(50);

        return response()->json($enrollments);
    }

    /** POST /school/course/classes/{class}/enroll */
    public function enroll(Request $request, CourseClass $courseClass): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($courseClass->school_id !== $school->id, 403);

        $data = $request->validate([
            'student_profile_id'   => 'required|integer',
            'enrolled_at'          => 'required|date',
            'monthly_fee_override' => 'nullable|numeric|min:0',
            'notes'                => 'nullable|string|max:500',
        ]);

        $profile = StudentProfile::where('id', $data['student_profile_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        // Prevent double-enroll
        $exists = CourseEnrollment::where('student_profile_id', $profile->id)
            ->where('course_class_id', $courseClass->id)
            ->exists();
        abort_if($exists, 422, 'Student is already enrolled in this class.');

        // Capacity check
        if ($courseClass->capacity) {
            $count = $courseClass->activeEnrollments()->count();
            abort_if($count >= $courseClass->capacity, 422, 'Class has reached its capacity.');
        }

        $enrollment = CourseEnrollment::create([
            'school_id'            => $school->id,
            'student_profile_id'   => $profile->id,
            'course_class_id'      => $courseClass->id,
            'monthly_fee_override' => $data['monthly_fee_override'] ?? null,
            'enrolled_at'          => $data['enrolled_at'],
            'status'               => 'active',
            'notes'                => $data['notes'] ?? null,
        ]);

        return response()->json($enrollment->load(['studentProfile.user', 'courseClass.course']), 201);
    }

    public function show(Request $request, CourseEnrollment $enrollment): JsonResponse
    {
        $this->assertOwns($request, $enrollment);

        return response()->json(
            $enrollment->load(['studentProfile.user', 'courseClass.course', 'monthlyStatuses', 'additionalCharges'])
        );
    }

    public function update(Request $request, CourseEnrollment $enrollment): JsonResponse
    {
        $this->assertOwns($request, $enrollment);

        $data = $request->validate([
            'status'               => 'sometimes|in:active,inactive,suspended',
            'monthly_fee_override' => 'nullable|numeric|min:0',
            'left_at'              => 'nullable|date',
            'notes'                => 'nullable|string|max:500',
        ]);

        $enrollment->update($data);

        return response()->json($enrollment->fresh(['studentProfile.user', 'courseClass.course']));
    }

    public function destroy(Request $request, CourseEnrollment $enrollment): JsonResponse
    {
        $this->assertOwns($request, $enrollment);
        $enrollment->delete();

        return response()->json(['message' => 'Enrollment removed.']);
    }

    private function assertOwns(Request $request, CourseEnrollment $enrollment): void
    {
        abort_if($enrollment->school_id !== $this->currentSchool($request)->id, 403);
    }
}
