<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreTimetableSlotRequest;
use App\Models\Classroom;
use App\Models\TimetableSlot;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableSlotController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $slots = $school->classrooms()
            ->when($request->classroom_id, fn ($q) => $q->where('id', $request->classroom_id))
            ->first()
            ? TimetableSlot::where('school_id', $school->id)
                ->when($request->classroom_id, fn ($q) => $q->where('classroom_id', $request->classroom_id))
                ->when($request->teacher_id, fn ($q) => $q->where('teacher_id', $request->teacher_id))
                ->with(['classroom.grade', 'teacher', 'room'])
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
            : collect();

        return response()->json($slots);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'classroom_id' => 'required|exists:classrooms,id',
            'teacher_id'   => 'required|exists:users,id',
            'room_id'      => 'nullable|exists:rooms,id',
            'subject'      => 'required|string|max:150',
            'day_of_week'  => 'required|integer|between:1,7',
            'start_time'   => 'required|date_format:H:i',
            'end_time'     => 'required|date_format:H:i|after:start_time',
        ]);

        $data['school_id'] = $school->id;

        $slot = TimetableSlot::create($data);

        return response()->json($slot->load(['classroom', 'teacher', 'room']), 201);
    }

    public function show(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->assert($request, $timetableSlot);

        return response()->json($timetableSlot->load(['classroom', 'teacher', 'room']));
    }

    public function update(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->assert($request, $timetableSlot);

        $data = $request->validate([
            'teacher_id'  => 'sometimes|exists:users,id',
            'room_id'     => 'nullable|exists:rooms,id',
            'subject'     => 'sometimes|string|max:150',
            'day_of_week' => 'sometimes|integer|between:1,7',
            'start_time'  => 'sometimes|date_format:H:i',
            'end_time'    => 'sometimes|date_format:H:i',
        ]);

        $timetableSlot->update($data);

        return response()->json($timetableSlot->fresh(['classroom', 'teacher', 'room']));
    }

    public function destroy(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->assert($request, $timetableSlot);
        $timetableSlot->delete();

        return response()->json(['message' => 'Slot deleted.']);
    }

    /** GET /api/v1/school/classes/{classroom}/timetable */
    public function forClassroom(Request $request, Classroom $classroom): JsonResponse
    {
        abort_if($classroom->school_id !== $this->currentSchool($request)->id, 403);

        return response()->json(
            $classroom->timetableSlots()
                ->with(['teacher:id,name', 'room'])
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
        );
    }

    private function assert(Request $request, TimetableSlot $slot): void
    {
        abort_if($slot->school_id !== $this->currentSchool($request)->id, 403);
    }
}
