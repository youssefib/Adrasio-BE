<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreStudentProfileRequest;
use App\Models\ClassGroupEnrollment;
use App\Models\CourseEnrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentProfileController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /api/v1/students
     * List all students with their profiles.
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $profiles = StudentProfile::with('user')
            ->where('school_id', $school->id)
            // Removed students keep their row (for payment history) but their user
            // is deactivated — hide them from the roster.
            ->whereHas('user', fn ($u) => $u->where('is_active', true))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) ($request->per_page ?: 25), 500));

        return response()->json($profiles);
    }

    /**
     * GET /api/v1/students/{student}
     */
    public function show(Request $request, StudentProfile $student): JsonResponse
    {
        $this->assertBelongsToSchool($request, $student);

        return response()->json(
            $student->load(['user', 'classrooms.grade', 'payments'])
        );
    }

    /**
     * PATCH /api/v1/students/{student}
     * Update profile data (admin/owner only).
     */
    public function update(StoreStudentProfileRequest $request, StudentProfile $student): JsonResponse
    {
        $this->assertBelongsToSchool($request, $student);

        $student->update($request->validated());

        return response()->json($student->fresh('user'));
    }

    /**
     * GET /api/v1/students/{student}/classes
     */
    public function classes(Request $request, StudentProfile $student): JsonResponse
    {
        $this->assertBelongsToSchool($request, $student);

        return response()->json(
            $student->classrooms()
                ->with(['grade', 'teacher', 'room'])
                ->get()
        );
    }

    /**
     * GET /api/v1/students/{student}/enrollments
     * The student's course + pack enrollments, for the refund picker.
     */
    public function enrollments(Request $request, StudentProfile $student): JsonResponse
    {
        $this->assertBelongsToSchool($request, $student);

        // Pack-member course enrollments are billed via the pack, not
        // individually — exclude them so only the pack enrollment is refundable.
        $course = CourseEnrollment::where('student_profile_id', $student->id)
            ->whereNull('class_group_enrollment_id')
            ->with('courseClass:id,name,monthly_fee')
            ->get()
            ->map(fn (CourseEnrollment $e) => [
                'id'     => $e->id,
                'type'   => 'course',
                'label'  => $e->courseClass?->name ?? '—',
                'fee'    => round($e->effectiveFee(), 2),
                'status' => $e->status,
            ])->values();

        $pack = ClassGroupEnrollment::where('student_profile_id', $student->id)
            ->with('group:id,name,monthly_price')
            ->get()
            ->map(fn (ClassGroupEnrollment $e) => [
                'id'     => $e->id,
                'type'   => 'pack',
                'label'  => $e->group?->name ?? '—',
                'fee'    => round($e->effectivePrice(), 2),
                'status' => $e->status,
            ])->values();

        return response()->json(['course' => $course, 'pack' => $pack]);
    }

    /**
     * DELETE /api/v1/students/{student}
     * Remove a student (owner/admin only).
     *
     * Payment history must survive, so we DON'T hard-delete: doing so would
     * cascade-destroy payments (payments.student_id), course_payments and
     * class_group_payments and corrupt past revenue. Instead we:
     *   - deactivate all course + pack enrollments (rows kept → payments kept),
     *   - drop classroom memberships (no financial data attached),
     *   - deactivate the user + revoke sessions and login credentials.
     * The user row is kept so historical reports still resolve the name.
     */
    public function destroy(Request $request, StudentProfile $student): JsonResponse
    {
        $this->assertBelongsToSchool($request, $student);

        $name      = $student->user?->name ?? $student->enrollment_number;
        $profileId = $student->id;

        DB::transaction(function () use ($student) {
            $today = now()->toDateString();

            // Deactivate enrollments — keep the rows so payment history survives.
            CourseEnrollment::where('student_profile_id', $student->id)->update(['status' => 'inactive']);
            CourseEnrollment::where('student_profile_id', $student->id)
                ->whereNull('left_at')->update(['left_at' => $today]);

            ClassGroupEnrollment::where('student_profile_id', $student->id)->update(['status' => 'inactive']);
            ClassGroupEnrollment::where('student_profile_id', $student->id)
                ->whereNull('left_at')->update(['left_at' => $today]);

            if ($student->user) {
                // Classroom memberships carry no financial data — safe to drop.
                DB::table('student_classroom')->where('student_id', $student->user->id)->delete();

                // Revoke access + end any active session. Keep name/phone for
                // reports; clear login credentials so the account can't be used
                // and the email is freed for reuse.
                $student->user->tokens()->delete();
                $student->user->update([
                    'is_active' => false,
                    'email'     => null,
                    'password'  => null,
                ]);
            }
        });

        ActivityLogger::log('student.deleted', "Student '{$name}' removed (records kept).", [
            'student_profile_id' => $profileId,
        ]);

        return response()->json(['message' => 'Student removed. Payment history kept.']);
    }

    private function assertBelongsToSchool(Request $request, StudentProfile $profile): void
    {
        abort_if($profile->school_id !== $this->currentSchool($request)->id, 403);
    }
}
