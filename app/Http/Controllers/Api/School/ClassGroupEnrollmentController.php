<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\ClassGroupEnrollment;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassGroupEnrollmentController extends Controller
{
    use ScopedToSchool;

    /** GET /school/course/group-enrollments?class_group_id=&student_profile_id=&status= */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $enrollments = $school->classGroupEnrollments()
            ->with(['studentProfile.user', 'group.classes'])
            ->when($request->class_group_id, fn ($q) => $q->where('class_group_id', $request->class_group_id))
            ->when($request->student_profile_id, fn ($q) => $q->where('student_profile_id', $request->student_profile_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('enrolled_at')
            ->paginate(50);

        return response()->json($enrollments);
    }

    /** POST /school/course/groups/{group}/enroll */
    public function enroll(Request $request, ClassGroup $group): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($group->school_id !== $school->id, 403);

        $data = $request->validate([
            'student_profile_id' => 'required|integer',
            'enrolled_at'        => 'required|date',
            'price_override'     => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string|max:500',
        ]);

        $profile = StudentProfile::where('id', $data['student_profile_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        abort_if(
            ClassGroupEnrollment::where('class_group_id', $group->id)
                ->where('student_profile_id', $profile->id)->exists(),
            422,
            'Student is already subscribed to this pack.',
        );

        $memberClassIds = $group->classes()->pluck('course_classes.id')->all();
        abort_if(empty($memberClassIds), 422, 'This pack has no classes.');

        // A pack subscription owns its member-class enrollments, so the student
        // must not already be enrolled individually in any member class.
        $clash = CourseEnrollment::where('student_profile_id', $profile->id)
            ->whereIn('course_class_id', $memberClassIds)
            ->with('courseClass:id,name')
            ->first();
        abort_if(
            $clash !== null,
            422,
            "Student is already enrolled in '{$clash?->courseClass?->name}'. Remove that enrollment before adding the pack.",
        );

        $groupEnrollment = DB::transaction(function () use ($school, $group, $profile, $data, $memberClassIds) {
            $groupEnrollment = ClassGroupEnrollment::create([
                'school_id'          => $school->id,
                'class_group_id'     => $group->id,
                'student_profile_id' => $profile->id,
                'price_override'     => $data['price_override'] ?? null,
                'enrolled_at'        => $data['enrolled_at'],
                'status'             => 'active',
                'notes'              => $data['notes'] ?? null,
            ]);

            foreach ($memberClassIds as $classId) {
                CourseEnrollment::create([
                    'school_id'                 => $school->id,
                    'student_profile_id'        => $profile->id,
                    'course_class_id'           => $classId,
                    'class_group_enrollment_id' => $groupEnrollment->id,
                    'monthly_fee_override'      => null, // revenue derives from the pack share
                    'enrolled_at'               => $data['enrolled_at'],
                    'status'                    => 'active',
                ]);
            }

            return $groupEnrollment;
        });

        return response()->json(
            $groupEnrollment->load(['studentProfile.user', 'group.classes', 'courseEnrollments']),
            201,
        );
    }

    /** PATCH /school/course/group-enrollments/{groupEnrollment} */
    public function update(Request $request, ClassGroupEnrollment $groupEnrollment): JsonResponse
    {
        $this->assertOwns($request, $groupEnrollment);

        $data = $request->validate([
            'status'         => 'sometimes|in:active,inactive,suspended',
            'price_override' => 'nullable|numeric|min:0',
            'left_at'        => 'nullable|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($groupEnrollment, $data) {
            $groupEnrollment->update($data);

            // Keep the member-class enrollments in step with the pack.
            $patch = array_intersect_key($data, array_flip(['status', 'left_at']));
            if ($patch) {
                $groupEnrollment->courseEnrollments()->update($patch);
            }
        });

        return response()->json($groupEnrollment->fresh(['studentProfile.user', 'group.classes']));
    }

    /** DELETE /school/course/group-enrollments/{groupEnrollment} */
    public function destroy(Request $request, ClassGroupEnrollment $groupEnrollment): JsonResponse
    {
        $this->assertOwns($request, $groupEnrollment);

        DB::transaction(function () use ($groupEnrollment) {
            // Remove the pack-created member-class enrollments, then the subscription.
            $groupEnrollment->courseEnrollments()->delete();
            $groupEnrollment->delete();
        });

        return response()->json(['message' => 'Pack subscription removed.']);
    }

    private function assertOwns(Request $request, ClassGroupEnrollment $groupEnrollment): void
    {
        abort_if($groupEnrollment->school_id !== $this->currentSchool($request)->id, 403);
    }
}
