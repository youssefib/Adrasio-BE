<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\User;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $classrooms = $school->classrooms()
            ->with(['grade', 'teacher', 'room'])
            ->withCount('students')
            ->when($request->grade_id, fn ($q) => $q->where('grade_id', $request->grade_id))
            ->when($request->academic_year, fn ($q) => $q->where('academic_year', $request->academic_year))
            ->orderBy('name')
            ->paginate(25);

        return response()->json($classrooms);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'grade_id'      => 'required|exists:grades,id',
            'room_id'       => 'nullable|exists:rooms,id',
            'teacher_id'    => 'nullable|exists:users,id',
            'name'          => 'required|string|max:100',
            'section'       => 'nullable|string|max:10',
            'capacity'      => 'sometimes|integer|min:1',
            'academic_year' => 'required|string|max:20',
        ]);

        abort_if(! $school->isWithinPlanLimits('classes'), 422, 'Plan class limit reached.');

        $this->assertSchoolOwns($school, 'grades', $data['grade_id']);
        if (!empty($data['room_id'])) {
            $this->assertSchoolOwns($school, 'rooms', $data['room_id']);
        }
        if (!empty($data['teacher_id'])) {
            $this->assertSchoolOwnsUser($school, $data['teacher_id']);
        }

        $classroom = $school->classrooms()->create($data);

        return response()->json($classroom->load(['grade', 'teacher', 'room']), 201);
    }

    public function show(Request $request, Classroom $classroom): JsonResponse
    {
        $this->assert($request, $classroom);

        return response()->json(
            $classroom->load(['grade', 'teacher', 'room', 'students', 'timetableSlots.teacher'])
        );
    }

    public function update(Request $request, Classroom $classroom): JsonResponse
    {
        $school = $this->currentSchool($request);
        $this->assert($request, $classroom);

        $data = $request->validate([
            'room_id'       => 'nullable|exists:rooms,id',
            'teacher_id'    => 'nullable|exists:users,id',
            'name'          => 'sometimes|string|max:100',
            'section'       => 'nullable|string|max:10',
            'capacity'      => 'sometimes|integer|min:1',
            'academic_year' => 'sometimes|string|max:20',
            'is_active'     => 'sometimes|boolean',
        ]);

        if (isset($data['room_id'])) {
            $this->assertSchoolOwns($school, 'rooms', $data['room_id']);
        }
        if (isset($data['teacher_id'])) {
            $this->assertSchoolOwnsUser($school, $data['teacher_id']);
        }

        $classroom->update($data);

        return response()->json($classroom->fresh(['grade', 'teacher', 'room']));
    }

    public function destroy(Request $request, Classroom $classroom): JsonResponse
    {
        $this->assert($request, $classroom);
        $classroom->delete();

        return response()->json(['message' => 'Classroom deleted.']);
    }

    /** Enroll a student in this classroom. */
    public function enroll(Request $request, Classroom $classroom): JsonResponse
    {
        $school = $this->currentSchool($request);
        $this->assert($request, $classroom);

        $data = $request->validate([
            'student_id'  => 'required|exists:users,id',
            'enrolled_at' => 'required|date',
        ]);

        $this->assertSchoolOwnsUser($school, $data['student_id']);

        $student = User::findOrFail($data['student_id']);
        abort_if(! $student->isStudent(), 422, 'User is not a student.');

        $classroom->students()->syncWithoutDetaching([
            $data['student_id'] => ['enrolled_at' => $data['enrolled_at']],
        ]);

        return response()->json(['message' => 'Student enrolled.']);
    }

    /** Remove a student from this classroom. */
    public function unenroll(Request $request, Classroom $classroom, int $studentId): JsonResponse
    {
        $this->assert($request, $classroom);

        $classroom->students()->detach($studentId);

        return response()->json(['message' => 'Student removed.']);
    }

    /** GET /api/v1/school/classes/{classroom}/students */
    public function students(Request $request, Classroom $classroom): JsonResponse
    {
        $this->assert($request, $classroom);

        return response()->json(
            $classroom->students()->with('studentProfile')->paginate(50)
        );
    }

    private function assert(Request $request, Classroom $classroom): void
    {
        abort_if($classroom->school_id !== $this->currentSchool($request)->id, 403);
    }

    private function assertSchoolOwns($school, string $table, int $id): void
    {
        abort_if(
            ! \DB::table($table)->where('id', $id)->where('school_id', $school->id)->exists(),
            403,
            "Resource #{$id} does not belong to this school."
        );
    }

    private function assertSchoolOwnsUser($school, int $userId): void
    {
        abort_if(
            ! \App\Models\User::where('id', $userId)->where('school_id', $school->id)->exists(),
            403,
            "User #{$userId} does not belong to this school."
        );
    }
}
