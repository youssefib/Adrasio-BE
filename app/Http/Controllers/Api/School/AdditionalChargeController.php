<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdditionalChargeController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $charges = $school->additionalCharges()
            ->with(['studentProfile.user', 'enrollment.courseClass'])
            ->when($request->student_profile_id, fn ($q) => $q->where('student_profile_id', $request->student_profile_id))
            ->when($request->enrollment_id, fn ($q) => $q->where('enrollment_id', $request->enrollment_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->year, fn ($q) => $q->whereYear('charge_date', $request->year))
            ->when($request->month, fn ($q) => $q->whereMonth('charge_date', $request->month))
            ->orderByDesc('charge_date')
            ->paginate(50);

        return response()->json($charges);
    }

    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'student_profile_id' => 'nullable|integer',
            'enrollment_id'      => 'nullable|integer',
            'description'        => 'required|string|max:255',
            'amount'             => 'required|numeric|min:0',
            'charge_date'        => 'required|date',
            'status'             => 'sometimes|in:pending,paid',
            'paid_at'            => 'nullable|date',
            'notes'              => 'nullable|string|max:500',
        ]);

        $charge = $school->additionalCharges()->create($data);

        return response()->json($charge->load(['studentProfile.user', 'enrollment.courseClass']), 201);
    }

    public function update(Request $request, AdditionalCharge $additionalCharge): JsonResponse
    {
        $this->assertOwns($request, $additionalCharge);

        $data = $request->validate([
            'description' => 'sometimes|string|max:255',
            'amount'      => 'sometimes|numeric|min:0',
            'charge_date' => 'sometimes|date',
            'status'      => 'sometimes|in:pending,paid',
            'paid_at'     => 'nullable|date',
            'notes'       => 'nullable|string|max:500',
        ]);

        // Auto-set paid_at when marking as paid
        if (($data['status'] ?? null) === 'paid' && ! $additionalCharge->paid_at && empty($data['paid_at'])) {
            $data['paid_at'] = now()->toDateString();
        }

        $additionalCharge->update($data);

        return response()->json($additionalCharge->fresh(['studentProfile.user']));
    }

    public function destroy(Request $request, AdditionalCharge $additionalCharge): JsonResponse
    {
        $this->assertOwns($request, $additionalCharge);
        $additionalCharge->delete();

        return response()->json(['message' => 'Charge deleted.']);
    }

    private function assertOwns(Request $request, AdditionalCharge $charge): void
    {
        abort_if($charge->school_id !== $this->currentSchool($request)->id, 403);
    }
}
