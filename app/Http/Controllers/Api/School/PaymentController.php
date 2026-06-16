<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StorePaymentRequest;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Services\ActivityLogger;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use ScopedToSchool;

    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $payments = $school->payments()
            ->with(['student', 'studentProfile.user', 'recordedBy'])
            ->when($request->student_profile_id, fn ($q) => $q->where('student_profile_id', $request->student_profile_id))
            ->when($request->year,   fn ($q) => $q->where('year', $request->year))
            ->when($request->month,  fn ($q) => $q->where('month', $request->month))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate(50);

        return response()->json($payments);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $school  = $this->currentSchool($request);
        $data    = $request->validated();

        $profile = StudentProfile::where('id', $data['student_profile_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        $payment = Payment::updateOrCreate(
            [
                'school_id'          => $school->id,
                'student_profile_id' => $profile->id,
                'student_id'         => $profile->user_id,
                'year'               => $data['year'],
                'month'              => $data['month'],
            ],
            array_merge($data, [
                'school_id'   => $school->id,
                'student_id'  => $profile->user_id,
                'recorded_by' => $request->user()->id,
            ])
        );

        ActivityLogger::log('payment.marked', "Payment marked as {$data['status']} for student #{$profile->id}.", [
            'payment_id' => $payment->id,
            'month'      => $data['month'],
            'year'       => $data['year'],
        ]);

        return response()->json($payment->load(['studentProfile.user', 'recordedBy']), 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSchool($request, $payment);

        return response()->json($payment->load(['studentProfile.user', 'recordedBy']));
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSchool($request, $payment);

        $data = $request->validate([
            'amount'  => 'sometimes|numeric|min:0',
            'status'  => 'sometimes|in:paid,unpaid,partial,waived',
            'notes'   => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $payment->update(array_merge($data, ['recorded_by' => $request->user()->id]));

        return response()->json($payment->fresh(['studentProfile.user', 'recordedBy']));
    }

    public function destroy(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSchool($request, $payment);
        $payment->delete();

        return response()->json(['message' => 'Payment record deleted.']);
    }

    /** GET /api/v1/students/{student}/payments?year=YYYY */
    public function studentSummary(Request $request, StudentProfile $student): JsonResponse
    {
        $school = $this->currentSchool($request);
        abort_if($student->school_id !== $school->id, 403);

        $payments = $school->payments()
            ->where('student_profile_id', $student->id)
            ->when($request->year, fn ($q) => $q->where('year', $request->year))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get(['year', 'month', 'amount', 'status', 'paid_at']);

        return response()->json($payments);
    }

    private function assertSchool(Request $request, Payment $payment): void
    {
        abort_if($payment->school_id !== $this->currentSchool($request)->id, 403);
    }
}
