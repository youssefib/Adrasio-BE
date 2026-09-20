<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Packs ("grouped classes") — a bundle of course classes sold for one
 * monthly price, split equally across the member classes.
 */
class ClassGroupController extends Controller
{
    use ScopedToSchool;

    /** GET /school/course/groups */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $groups = $school->classGroups()
            ->with(['classes:id,name,monthly_fee,teacher_id,teacher_share_pct', 'classes.teacher:id,name'])
            ->withCount(['classes', 'activeEnrollments'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($groups);
    }

    /** POST /school/course/groups */
    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $data   = $this->validateData($request, $school->id);

        $group = ClassGroup::create([
            'school_id'     => $school->id,
            'name'          => $data['name'],
            'monthly_price' => $data['monthly_price'],
            'status'        => $data['status'] ?? 'active',
        ]);

        $group->classes()->sync($data['course_class_ids']);

        return response()->json($group->load('classes'), 201);
    }

    /** GET /school/course/groups/{group} */
    public function show(Request $request, ClassGroup $group): JsonResponse
    {
        $this->assertOwns($request, $group);

        return response()->json(
            $group->load(['classes.teacher:id,name', 'classes.course:id,name'])
                ->loadCount(['classes', 'activeEnrollments'])
        );
    }

    /** PATCH /school/course/groups/{group} */
    public function update(Request $request, ClassGroup $group): JsonResponse
    {
        $this->assertOwns($request, $group);
        $data = $this->validateData($request, $group->school_id, partial: true);

        $group->update(array_filter(
            [
                'name'          => $data['name'] ?? null,
                'monthly_price' => $data['monthly_price'] ?? null,
                'status'        => $data['status'] ?? null,
            ],
            fn ($v) => $v !== null,
        ));

        if (array_key_exists('course_class_ids', $data)) {
            $group->classes()->sync($data['course_class_ids']);
        }

        return response()->json($group->fresh(['classes'])->loadCount(['classes', 'activeEnrollments']));
    }

    /** DELETE /school/course/groups/{group} */
    public function destroy(Request $request, ClassGroup $group): JsonResponse
    {
        $this->assertOwns($request, $group);

        abort_if(
            $group->enrollments()->exists(),
            422,
            'This pack has student subscriptions. Remove them before deleting the pack.',
        );

        $group->delete();

        return response()->json(['message' => 'Pack deleted.']);
    }

    private function validateData(Request $request, int $schoolId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name'             => "{$required}|string|max:255",
            'monthly_price'    => "{$required}|numeric|min:0",
            'status'           => 'sometimes|in:active,inactive',
            'course_class_ids' => "{$required}|array|min:1",
            'course_class_ids.*' => 'integer',
        ]);

        // Every member class must belong to this school.
        if (isset($data['course_class_ids'])) {
            $valid = \App\Models\CourseClass::whereIn('id', $data['course_class_ids'])
                ->where('school_id', $schoolId)
                ->pluck('id')
                ->all();
            abort_if(count($valid) !== count($data['course_class_ids']), 422, 'One or more classes are invalid.');
        }

        return $data;
    }

    private function assertOwns(Request $request, ClassGroup $group): void
    {
        abort_if($group->school_id !== $this->currentSchool($request)->id, 403);
    }
}
