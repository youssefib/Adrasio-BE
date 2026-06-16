<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoursePaymentController extends Controller
{
    use ScopedToSchool;

    /** GET /school/course/payments?course_class_id=&month=&year= */
    public function index(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $payments = CoursePayment::where('school_id', $school->id)
            ->with(['enrollment.studentProfile.user', 'enrollment.courseClass'])
            ->when($request->course_class_id, function ($q) use ($request) {
                $q->whereHas('enrollment', fn ($q2) => $q2->where('course_class_id', $request->course_class_id));
            })
            ->when($request->student_profile_id, function ($q) use ($request) {
                $q->whereHas('enrollment', fn ($q2) => $q2->where('student_profile_id', $request->student_profile_id));
            })
            ->when($request->month, fn ($q) => $q->where('month', $request->month))
            ->when($request->year,  fn ($q) => $q->where('year',  $request->year))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json($payments);
    }

    /** GET /school/course/payments/unpaid?month=&year= — for dashboard */
    public function unpaid(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $now    = now();
        $month  = (int) ($request->month ?? $now->month);
        $year   = (int) ($request->year  ?? $now->year);

        $unpaid = CourseEnrollment::where('school_id', $school->id)
            ->where('status', 'active')
            ->with(['studentProfile.user', 'courseClass'])
            ->whereDoesntHave('payments', function ($q) use ($month, $year) {
                $q->where('month', $month)
                  ->where('year',  $year)
                  ->whereIn('status', ['paid', 'waived']);
            })
            ->get()
            ->map(fn ($e) => [
                'enrollment_id'   => $e->id,
                'student_name'    => $e->studentProfile?->user?->name ?? '—',
                'class_name'      => $e->courseClass?->name ?? '—',
                'class_id'        => $e->course_class_id,
                'expected_amount' => (float) ($e->monthly_fee_override ?? $e->courseClass?->monthly_fee ?? 0),
            ]);

        return response()->json(['month' => $month, 'year' => $year, 'items' => $unpaid]);
    }

    /** POST /school/course/payments */
    public function store(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $data = $request->validate([
            'course_enrollment_id' => 'required|integer',
            'month'                => 'required|integer|between:1,12',
            'year'                 => 'required|integer|min:2020|max:2100',
            'amount'               => 'required|numeric|min:0',
            'status'               => 'sometimes|in:pending,paid,waived',
            'notes'                => 'nullable|string|max:255',
            'paid_at'              => 'nullable|date',
        ]);

        $enrollment = CourseEnrollment::where('id', $data['course_enrollment_id'])
            ->where('school_id', $school->id)
            ->firstOrFail();

        if (($data['status'] ?? 'pending') === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $payment = CoursePayment::updateOrCreate(
            [
                'course_enrollment_id' => $enrollment->id,
                'month'                => $data['month'],
                'year'                 => $data['year'],
            ],
            array_merge($data, [
                'school_id'   => $school->id,
                'recorded_by' => $request->user()->id,
            ])
        );

        return response()->json(
            $payment->fresh(['enrollment.studentProfile.user', 'enrollment.courseClass']),
            201
        );
    }

    /** PATCH /school/course/payments/{coursePayment} */
    public function update(Request $request, CoursePayment $coursePayment): JsonResponse
    {
        abort_if($coursePayment->school_id !== $this->currentSchool($request)->id, 403, 'Forbidden.');

        $data = $request->validate([
            'amount'  => 'sometimes|numeric|min:0',
            'status'  => 'sometimes|in:pending,paid,waived',
            'notes'   => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
        ]);

        if (isset($data['status']) && $data['status'] === 'paid'
            && ! $coursePayment->paid_at && ! isset($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $coursePayment->update($data);

        return response()->json(
            $coursePayment->fresh(['enrollment.studentProfile.user', 'enrollment.courseClass'])
        );
    }

    /** DELETE /school/course/payments/{coursePayment} */
    public function destroy(Request $request, CoursePayment $coursePayment): JsonResponse
    {
        abort_if($coursePayment->school_id !== $this->currentSchool($request)->id, 403, 'Forbidden.');
        $coursePayment->delete();

        return response()->json(['message' => 'Payment deleted.']);
    }
}
