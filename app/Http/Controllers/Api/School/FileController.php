<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreFileRequest;
use App\Models\File;
use App\Services\ActivityLogger;
use App\Services\SubscriptionLimitService;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $user   = $request->user();

        $query = File::where('school_id', $school->id)->with(['uploader', 'grade', 'classroom']);

        if ($user->isStudent()) {
            $classroomIds = $user->enrolledClasses()->pluck('classrooms.id');
            $gradeIds     = $user->enrolledClasses()->pluck('grade_id');
            $query->where(function ($q) use ($classroomIds, $gradeIds) {
                $q->whereIn('classroom_id', $classroomIds)
                  ->orWhereIn('grade_id', $gradeIds)
                  ->orWhere('visibility', 'school');
            });
        } elseif ($user->isTeacher()) {
            $classroomIds = $user->taughtClasses()->pluck('id');
            $gradeIds     = $user->taughtClasses()->pluck('grade_id');
            $query->where(function ($q) use ($classroomIds, $gradeIds, $user) {
                $q->whereIn('classroom_id', $classroomIds)
                  ->orWhereIn('grade_id', $gradeIds)
                  ->orWhere('uploaded_by', $user->id)
                  ->orWhere('visibility', 'school');
            });
        }

        $query->when($request->grade_id,     fn ($q) => $q->where('grade_id', $request->grade_id))
              ->when($request->classroom_id, fn ($q) => $q->where('classroom_id', $request->classroom_id))
              ->when($request->visibility,   fn ($q) => $q->where('visibility', $request->visibility));

        return response()->json($query->latest()->paginate(25));
    }

    public function store(StoreFileRequest $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $data   = $request->validated();

        // Enforce storage limit
        SubscriptionLimitService::for($school)->enforceStorage($request->file('file')->getSize());

        $uploaded = $request->file('file');
        $path     = $uploaded->store("schools/{$school->id}/files", 'local');

        $file = File::create([
            'school_id'    => $school->id,
            'uploaded_by'  => $request->user()->id,
            'grade_id'     => $data['grade_id'] ?? null,
            'classroom_id' => $data['classroom_id'] ?? null,
            'title'        => $data['title'],
            'description'  => $data['description'] ?? null,
            'visibility'   => $data['visibility'] ?? 'class',
            'disk'         => 'local',
            'path'         => $path,
            'mime_type'    => $uploaded->getMimeType(),
            'file_type'    => $uploaded->getClientOriginalExtension(),
            'size_bytes'   => $uploaded->getSize(),
        ]);

        ActivityLogger::log('file.uploaded', "File '{$file->title}' uploaded.", ['file_id' => $file->id]);

        return response()->json($file->load(['uploader', 'grade', 'classroom']), 201);
    }

    public function show(Request $request, File $file): JsonResponse
    {
        $this->assertSchool($request, $file);
        $this->authorize('view', $file);

        return response()->json($file->load(['uploader', 'grade', 'classroom']));
    }

    public function update(Request $request, File $file): JsonResponse
    {
        $this->assertSchool($request, $file);
        $this->authorize('update', $file);

        $data = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'description'  => 'nullable|string',
            'grade_id'     => 'nullable|exists:grades,id',
            'classroom_id' => 'nullable|exists:classrooms,id',
            'visibility'   => 'sometimes|in:class,grade,school',
        ]);

        $file->update($data);

        return response()->json($file->fresh(['uploader', 'grade', 'classroom']));
    }

    public function destroy(Request $request, File $file): JsonResponse
    {
        $this->assertSchool($request, $file);
        $this->authorize('delete', $file);

        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        ActivityLogger::log('file.deleted', "File '{$file->title}' deleted.");

        return response()->json(['message' => 'File deleted.']);
    }

    /**
     * Stream a file download directly (no signed URL needed for local disk).
     */
    public function download(Request $request, File $file): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->assertSchool($request, $file);
        $this->authorize('view', $file);

        abort_if(! Storage::disk($file->disk)->exists($file->path), 404, 'File not found on disk.');

        return Storage::disk($file->disk)->download($file->path, $file->title);
    }

    private function assertSchool(Request $request, File $file): void
    {
        abort_if($file->school_id !== $this->currentSchool($request)->id, 403);
    }
}
