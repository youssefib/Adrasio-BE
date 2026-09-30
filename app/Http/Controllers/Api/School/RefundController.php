<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ClassGroupEnrollment;
use App\Models\CourseEnrollment;
use App\Models\Refund;
use App\Models\StudentProfile;
use App\Services\ActivityLogger;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /school/refunds?year=&month=
     * List refunds, most recent first, optionally filtered by month.
     */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $refunds = Refund::where('school_id', $school->id)
            ->with([
                'studentProfile.user:id,name',
                'courseEnrollment.courseClass:id,name',
                'classGroupEnrollment.group:id,name',
            ])
            ->when($request->year, fn ($q) => $q->whereYear('refunded_at', (int) $request->year))
            ->when($request->month, fn ($q) => $q->whereMonth('refunded_at', (int) $request->month))
            ->orderByDesc('refunded_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Refund $r) => [
                'id'          => $r->id,
                'student'     => $r->studentProfile?->user?->name ?? '—',
                'enrollment'  => $r->courseEnrollment?->courseClass?->name
                                 ?? $r->classGroupEnrollment?->group?->name
                                 ?? '—',
                'type'        => $r->course_enrollment_id ? 'course' : ($r->class_group_enrollment_id ? 'pack' : null),
                'amount'      => (float) $r->amount,
                'reason'      => $r->reason,
                'refunded_at' => $r->refunded_at?->toDateString(),
            ]);

        return response()->json(['data' => $refunds, 'total' => $refunds->count()]);
    }

    /**
     * POST /school/refunds
     */
    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'student_profile_id'        => 'required|integer|exists:student_profiles,id',
            'course_enrollment_id'      => 'nullable|integer|exists:course_enrollments,id',
            'class_group_enrollment_id' => 'nullable|integer|exists:class_group_enrollments,id',
            'amount'                    => 'required|numeric|min:0.01',
            'reason'                    => 'nullable|string|max:255',
            'refunded_at'               => 'required|date',
        ]);

        // Student must belong to this school.
        $student = StudentProfile::where('id', $data['student_profile_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        // Exactly one enrollment must be chosen, and it must belong to the student.
        $courseId = $data['course_enrollment_id'] ?? null;
        $packId   = $data['class_group_enrollment_id'] ?? null;

        if ((bool) $courseId === (bool) $packId) {
            throw ValidationException::withMessages([
                'course_enrollment_id' => ['Select exactly one enrollment to refund.'],
            ]);
        }

        if ($courseId) {
            CourseEnrollment::where('id', $courseId)
                ->where('school_id', $school->id)
                ->where('student_profile_id', $student->id)
                ->firstOrFail();
        } else {
            ClassGroupEnrollment::where('id', $packId)
                ->where('school_id', $school->id)
                ->where('student_profile_id', $student->id)
                ->firstOrFail();
        }

        $refund = Refund::create([
            'school_id'                 => $school->id,
            'student_profile_id'        => $student->id,
            'course_enrollment_id'      => $courseId,
            'class_group_enrollment_id' => $packId,
            'amount'                    => $data['amount'],
            'reason'                    => $data['reason'] ?? null,
            'refunded_at'               => $data['refunded_at'],
            'recorded_by'               => $request->user()?->id,
        ]);

        ActivityLogger::log('refund.created', "Refund of {$refund->amount} recorded.", [
            'refund_id'          => $refund->id,
            'student_profile_id' => $student->id,
        ]);

        return response()->json($refund->load([
            'studentProfile.user:id,name',
            'courseEnrollment.courseClass:id,name',
            'classGroupEnrollment.group:id,name',
        ]), 201);
    }

    /**
     * DELETE /school/refunds/{refund}
     */
    public function destroy(Request $request, Refund $refund): JsonResponse
    {
        abort_if($refund->school_id !== $this->currentSchool($request)->id, 403);

        $refund->delete();

        ActivityLogger::log('refund.deleted', "Refund #{$refund->id} deleted.", [
            'refund_id' => $refund->id,
        ]);

        return response()->json(['message' => 'Refund deleted.']);
    }
}
