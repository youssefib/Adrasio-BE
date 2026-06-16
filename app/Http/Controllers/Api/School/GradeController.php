<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $grades = $this->currentSchool($request)
            ->grades()
            ->withCount('classrooms')
            ->orderBy('order')
            ->get();

        return response()->json($grades);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'order'       => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $grade = $school->grades()->create($data);

        return response()->json($grade, 201);
    }

    public function show(Request $request, Grade $grade): JsonResponse
    {
        $this->assertBelongsToSchool($request, $grade);

        return response()->json($grade->load('classrooms'));
    }

    public function update(Request $request, Grade $grade): JsonResponse
    {
        $this->assertBelongsToSchool($request, $grade);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'order'       => 'sometimes|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $grade->update($data);

        return response()->json($grade->fresh());
    }

    public function destroy(Request $request, Grade $grade): JsonResponse
    {
        $this->assertBelongsToSchool($request, $grade);
        $grade->delete();

        return response()->json(['message' => 'Grade deleted.']);
    }

    private function assertBelongsToSchool(Request $request, Grade $grade): void
    {
        abort_if($grade->school_id !== $this->currentSchool($request)->id, 403);
    }
}
