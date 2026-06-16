<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreStudentProfileRequest;
use App\Models\StudentProfile;
use App\Models\User;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->orderBy('created_at', 'desc')
            ->paginate(25);

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

    private function assertBelongsToSchool(Request $request, StudentProfile $profile): void
    {
        abort_if($profile->school_id !== $this->currentSchool($request)->id, 403);
    }
}
