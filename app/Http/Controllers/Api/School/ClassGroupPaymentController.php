<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ClassGroupEnrollment;
use App\Models\ClassGroupPayment;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly payments for pack subscriptions. One payment per subscription per
 * month (the student pays the pack price once, not per member class).
 */
class ClassGroupPaymentController extends Controller
{
    use ScopedToSchool;

    /** GET /school/course/group-payments?class_group_enrollment_id=&month=&year=&status= */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $payments = ClassGroupPayment::where('school_id', $school->id)
            ->with(['enrollment.studentProfile.user', 'enrollment.group:id,name,monthly_price'])
            ->when($request->class_group_enrollment_id, fn ($q) => $q->where('class_group_enrollment_id', $request->class_group_enrollment_id))
            ->when($request->month, fn ($q) => $q->where('month', $request->month))
            ->when($request->year, fn ($q) => $q->where('year', $request->year))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('year')->orderByDesc('month')
            ->paginate(50);

        return response()->json($payments);
    }

    /** GET /school/course/group-payments/unpaid?month=&year= */
    public function unpaid(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $data = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2020|max:2100',
        ]);

        $enrollments = $school->classGroupEnrollments()
            ->where('status', 'active')
            ->whereNull('left_at')
            ->with(['studentProfile.user', 'group:id,name,monthly_price'])
            ->whereDoesntHave('payments', function ($q) use ($data) {
                $q->where('month', $data['month'])
                  ->where('year', $data['year'])
                  ->whereIn('status', ['paid', 'waived']);
            })
            ->get()
            ->map(fn (ClassGroupEnrollment $e) => [
                'class_group_enrollment_id' => $e->id,
                'student'                   => $e->studentProfile?->user?->name,
                'pack'                      => $e->group?->name,
                'expected_amount'           => $e->effectivePrice(),
            ]);

        return response()->json($enrollments);
    }

    /** POST /school/course/group-payments */
    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'class_group_enrollment_id' => 'required|integer',
            'month'  => 'required|integer|between:1,12',
            'year'   => 'required|integer|min:2020|max:2100',
            'amount' => 'required|numeric|min:0',
            'status' => 'sometimes|in:pending,paid,waived',
            'notes'  => 'nullable|string|max:255',
        ]);

        $enrollment = ClassGroupEnrollment::where('id', $data['class_group_enrollment_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        $payment = ClassGroupPayment::updateOrCreate(
            [
                'class_group_enrollment_id' => $enrollment->id,
                'month' => $data['month'],
                'year'  => $data['year'],
            ],
            [
                'school_id'   => $school->id,
                'amount'      => $data['amount'],
                'status'      => $data['status'] ?? 'paid',
                'notes'       => $data['notes'] ?? null,
                'paid_at'     => ($data['status'] ?? 'paid') === 'paid' ? now() : null,
                'recorded_by' => $request->user()->id,
            ],
        );

        return response()->json($payment->load('enrollment.studentProfile.user'), 201);
    }

    /** PATCH /school/course/group-payments/{groupPayment} */
    public function update(Request $request, ClassGroupPayment $groupPayment): JsonResponse
    {
        abort_if($groupPayment->school_id !== $this->currentSchool($request)->id, 403);

        $data = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:pending,paid,waived',
            'notes'  => 'nullable|string|max:255',
        ]);

        if (($data['status'] ?? null) === 'paid' && ! $groupPayment->paid_at) {
            $data['paid_at'] = now();
        }

        $groupPayment->update($data);

        return response()->json($groupPayment->fresh('enrollment.studentProfile.user'));
    }

    /** DELETE /school/course/group-payments/{groupPayment} */
    public function destroy(Request $request, ClassGroupPayment $groupPayment): JsonResponse
    {
        abort_if($groupPayment->school_id !== $this->currentSchool($request)->id, 403);
        $groupPayment->delete();

        return response()->json(['message' => 'Payment removed.']);
    }
}
