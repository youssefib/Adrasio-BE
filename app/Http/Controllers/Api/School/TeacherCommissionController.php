<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\TeacherCommission;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherCommissionController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $commissions = $school->teacherCommissions()
            ->with(['teacher:id,name,email', 'courseClass:id,name,course_id'])
            ->when($request->teacher_id, fn ($q) => $q->where('teacher_id', $request->teacher_id))
            ->when($request->course_class_id, fn ($q) => $q->where('course_class_id', $request->course_class_id))
            ->orderByDesc('effective_from')
            ->get();

        return response()->json($commissions);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'teacher_id'      => 'required|integer',
            'course_class_id' => 'nullable|integer',
            'commission_type' => 'required|in:per_student,per_class,fixed_monthly',
            'amount'          => 'required|numeric|min:0',
            'effective_from'  => 'required|date',
            'effective_to'    => 'nullable|date|after:effective_from',
        ]);

        // Verify teacher belongs to school
        abort_if(
            ! \App\Models\User::where('id', $data['teacher_id'])->where('school_id', $school->id)->exists(),
            403, 'Teacher does not belong to this school.'
        );

        if (! empty($data['course_class_id'])) {
            abort_if(
                ! \DB::table('course_classes')->where('id', $data['course_class_id'])->where('school_id', $school->id)->exists(),
                403, 'Class does not belong to this school.'
            );
        }

        $commission = $school->teacherCommissions()->create($data);

        return response()->json($commission->load(['teacher:id,name', 'courseClass:id,name']), 201);
    }

    public function update(Request $request, TeacherCommission $teacherCommission): JsonResponse
    {
        $this->assertOwns($request, $teacherCommission);

        $data = $request->validate([
            'commission_type' => 'sometimes|in:per_student,per_class,fixed_monthly',
            'amount'          => 'sometimes|numeric|min:0',
            'effective_from'  => 'sometimes|date',
            'effective_to'    => 'nullable|date',
        ]);

        $teacherCommission->update($data);

        return response()->json($teacherCommission->fresh(['teacher:id,name', 'courseClass:id,name']));
    }

    public function destroy(Request $request, TeacherCommission $teacherCommission): JsonResponse
    {
        $this->assertOwns($request, $teacherCommission);
        $teacherCommission->delete();

        return response()->json(['message' => 'Commission rule deleted.']);
    }

    private function assertOwns(Request $request, TeacherCommission $tc): void
    {
        abort_if($tc->school_id !== $this->currentSchool($request)->id, 403);
    }
}
