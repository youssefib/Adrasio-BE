<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseLevelController extends Controller
{
    use ScopedToSchool;

    public function store(Request $request, Course $course): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($course->school_id !== $school->id, 403);

        $data = $request->validate([
            'name'  => 'required|string|max:80',
            'order' => 'nullable|integer|min:0',
        ]);

        $level = $course->levels()->create(array_merge($data, ['school_id' => $school->id]));

        return response()->json($level, 201);
    }

    public function update(Request $request, Course $course, CourseLevel $level): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($course->school_id !== $school->id || $level->course_id !== $course->id, 403);

        $data = $request->validate([
            'name'  => 'sometimes|string|max:80',
            'order' => 'sometimes|integer|min:0',
        ]);

        $level->update($data);

        return response()->json($level->fresh());
    }

    public function destroy(Request $request, Course $course, CourseLevel $level): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($course->school_id !== $school->id || $level->course_id !== $course->id, 403);

        $level->delete();

        return response()->json(['message' => 'Level deleted.']);
    }
}
