<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseClassController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $classes = $school->courseClasses()
            ->with(['course', 'level', 'teacher:id,name,email', 'room', 'sessions'])
            ->withCount('enrollments', 'activeEnrollments')
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->teacher_id, fn ($q) => $q->where('teacher_id', $request->teacher_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($classes);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'course_id'       => 'required|integer',
            'course_level_id' => 'nullable|integer',
            'teacher_id'      => 'nullable|integer',
            'room_id'         => 'nullable|integer',
            'name'            => 'required|string|max:120',
            'monthly_fee'     => 'required|numeric|min:0',
            'capacity'        => 'nullable|integer|min:1',
            'status'          => 'sometimes|in:active,inactive',
            'sessions'                        => 'nullable|array',
            'sessions.*.day_of_week'          => 'required_with:sessions|integer|between:1,7',
            'sessions.*.start_time'           => 'required_with:sessions|date_format:H:i',
            'sessions.*.end_time'             => 'required_with:sessions|date_format:H:i',
            'sessions.*.duration_minutes'     => 'nullable|integer|min:1',
            'sessions.*.room_id'              => 'nullable|integer',
        ]);

        abort_if(
            ! \DB::table('courses')->where('id', $data['course_id'])->where('school_id', $school->id)->exists(),
            403, 'Course does not belong to this school.'
        );

        $class = $school->courseClasses()->create([
            'course_id'       => $data['course_id'],
            'course_level_id' => $data['course_level_id'] ?? null,
            'teacher_id'      => $data['teacher_id'] ?? null,
            'room_id'         => $data['room_id'] ?? null,
            'name'            => $data['name'],
            'monthly_fee'     => $data['monthly_fee'],
            'capacity'        => $data['capacity'] ?? null,
            'status'          => $data['status'] ?? 'active',
        ]);

        foreach ($data['sessions'] ?? [] as $s) {
            $start    = \Carbon\Carbon::createFromFormat('H:i', $s['start_time']);
            $end      = \Carbon\Carbon::createFromFormat('H:i', $s['end_time']);
            $duration = $s['duration_minutes'] ?? max(1, $start->diffInMinutes($end));

            $class->sessions()->create([
                'day_of_week'      => $s['day_of_week'],
                'start_time'       => $s['start_time'],
                'end_time'         => $s['end_time'],
                'duration_minutes' => $duration,
                'room_id'          => $s['room_id'] ?? null,
            ]);
        }

        return response()->json($class->load(['course', 'level', 'teacher:id,name', 'room', 'sessions.room']), 201);
    }

    public function show(Request $request, CourseClass $courseClass): JsonResponse
    {
        $this->assertOwns($request, $courseClass);

        return response()->json(
            $courseClass->load([
                'course', 'level', 'teacher:id,name,email', 'room', 'sessions',
                'enrollments.studentProfile.user',
                'commissions.teacher:id,name',
            ])->loadCount('enrollments', 'activeEnrollments')
        );
    }

    public function update(Request $request, CourseClass $courseClass): JsonResponse
    {
        $this->assertOwns($request, $courseClass);

        $data = $request->validate([
            'course_level_id' => 'nullable|integer',
            'teacher_id'      => 'nullable|integer',
            'room_id'         => 'nullable|integer',
            'name'            => 'sometimes|string|max:120',
            'monthly_fee'     => 'sometimes|numeric|min:0',
            'capacity'        => 'nullable|integer|min:1',
            'status'          => 'sometimes|in:active,inactive',
        ]);

        $courseClass->update($data);

        return response()->json($courseClass->fresh(['course', 'level', 'teacher:id,name', 'room', 'sessions.room']));
    }

    public function destroy(Request $request, CourseClass $courseClass): JsonResponse
    {
        $this->assertOwns($request, $courseClass);
        $courseClass->delete();

        return response()->json(['message' => 'Class deleted.']);
    }

    /** PUT /school/course/classes/{courseClass}/sessions — replace all sessions */
    public function updateSessions(Request $request, CourseClass $courseClass): JsonResponse
    {
        $this->assertOwns($request, $courseClass);

        $data = $request->validate([
            'sessions'                    => 'required|array|min:1',
            'sessions.*.day_of_week'      => 'required|integer|between:1,7',
            'sessions.*.start_time'       => 'required|date_format:H:i',
            'sessions.*.end_time'         => 'required|date_format:H:i',
            'sessions.*.duration_minutes' => 'nullable|integer|min:1',
            'sessions.*.room_id'          => 'nullable|integer',
        ]);

        $courseClass->sessions()->delete();

        foreach ($data['sessions'] as $s) {
            $start    = \Carbon\Carbon::createFromFormat('H:i', $s['start_time']);
            $end      = \Carbon\Carbon::createFromFormat('H:i', $s['end_time']);
            $duration = $s['duration_minutes'] ?? max(1, $start->diffInMinutes($end));

            $courseClass->sessions()->create([
                'day_of_week'      => $s['day_of_week'],
                'start_time'       => $s['start_time'],
                'end_time'         => $s['end_time'],
                'duration_minutes' => $duration,
                'room_id'          => $s['room_id'] ?? null,
            ]);
        }

        return response()->json($courseClass->fresh(['sessions.room']));
    }

    /**
     * GET /school/course/classes/availability
     * Check teacher and room availability for a proposed session slot.
     *
     * Query params:
     *   teacher_id, room_id (optional), day_of_week, start_time, end_time,
     *   exclude_class_id (the class being edited, to ignore its own sessions)
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $day            = (int) $request->input('day_of_week');
        $start          = $request->input('start_time');   // H:i
        $end            = $request->input('end_time');     // H:i
        $excludeClassId = $request->input('exclude_class_id');

        $result = [
            'teacher_free'          => true,
            'teacher_conflict_class' => null,
            'room_free'             => true,
            'room_conflict_class'   => null,
        ];

        // ── Teacher check ──────────────────────────────────────────────────
        if ($request->filled('teacher_id') && $day && $start && $end) {
            $conflict = ClassSession::where('day_of_week', $day)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start)
                ->whereHas('courseClass', function ($q) use ($school, $request, $excludeClassId) {
                    $q->where('school_id', $school->id)
                      ->where('teacher_id', $request->input('teacher_id'));
                    if ($excludeClassId) {
                        $q->where('id', '!=', $excludeClassId);
                    }
                })
                ->with('courseClass:id,name')
                ->first();

            if ($conflict) {
                $result['teacher_free']           = false;
                $result['teacher_conflict_class'] = $conflict->courseClass?->name;
            }
        }

        // ── Room check ─────────────────────────────────────────────────────
        if ($request->filled('room_id') && $day && $start && $end) {
            $roomId = (int) $request->input('room_id');

            // A session uses a room if:
            //   1. session.room_id = $roomId  (per-session override)
            //   2. session.room_id IS NULL AND courseClass.room_id = $roomId (class default)
            $conflict = ClassSession::where('day_of_week', $day)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start)
                ->where(function ($q) use ($roomId) {
                    $q->where('room_id', $roomId)
                      ->orWhere(function ($q2) use ($roomId) {
                          $q2->whereNull('room_id')
                             ->whereHas('courseClass', fn ($q3) => $q3->where('room_id', $roomId));
                      });
                })
                ->whereHas('courseClass', function ($q) use ($school, $excludeClassId) {
                    $q->where('school_id', $school->id);
                    if ($excludeClassId) {
                        $q->where('id', '!=', $excludeClassId);
                    }
                })
                ->with('courseClass:id,name')
                ->first();

            if ($conflict) {
                $result['room_free']             = false;
                $result['room_conflict_class']   = $conflict->courseClass?->name;
            }
        }

        return response()->json($result);
    }

    private function assertOwns(Request $request, CourseClass $courseClass): void
    {
        abort_if($courseClass->school_id !== $this->currentSchool($request)->id, 403);
    }
}
