<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $courses = $school->courses()
            ->withCount('levels', 'classes')
            ->with('levels')
            ->orderBy('name')
            ->get();

        return response()->json($courses);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'levels'      => 'nullable|array',
            'levels.*.name'  => 'required_with:levels|string|max:80',
            'levels.*.order' => 'nullable|integer|min:0',
        ]);

        $course = $school->courses()->create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'color'       => $data['color'] ?? '#6366f1',
        ]);

        foreach ($data['levels'] ?? [] as $i => $lvl) {
            $course->levels()->create([
                'school_id' => $school->id,
                'name'      => $lvl['name'],
                'order'     => $lvl['order'] ?? $i,
            ]);
        }

        return response()->json($course->load('levels'), 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->assertOwns($request, $course);

        return response()->json($course->load(['levels', 'classes.teacher', 'classes.sessions']));
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $this->assertOwns($request, $course);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'nullable|string|max:500',
            'color'       => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $course->update($data);

        return response()->json($course->fresh('levels'));
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        $this->assertOwns($request, $course);
        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    private function assertOwns(Request $request, Course $course): void
    {
        abort_if($course->school_id !== $this->currentSchool($request)->id, 403);
    }
}
