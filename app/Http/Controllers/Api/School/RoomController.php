<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $rooms = $this->currentSchool($request)
            ->rooms()
            ->orderBy('name')
            ->get();

        return response()->json($rooms);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => 'nullable|string|max:20',
            'capacity'     => 'sometimes|integer|min:1',
            'is_available' => 'sometimes|boolean',
        ]);

        $room = $school->rooms()->create($data);

        return response()->json($room, 201);
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        $this->assert($request, $room);

        return response()->json($room);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $this->assert($request, $room);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'code'         => 'nullable|string|max:20',
            'capacity'     => 'sometimes|integer|min:1',
            'is_available' => 'sometimes|boolean',
        ]);

        $room->update($data);

        return response()->json($room->fresh());
    }

    public function destroy(Request $request, Room $room): JsonResponse
    {
        $this->assert($request, $room);
        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }

    private function assert(Request $request, Room $room): void
    {
        abort_if($room->school_id !== $this->currentSchool($request)->id, 403);
    }
}
