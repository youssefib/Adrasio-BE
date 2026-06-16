<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CourseEnrollment;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ScopedToSchool;

    public function __invoke(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $now    = now();
        $year   = $now->year;
        $month  = $now->month;

        // ── User counts ───────────────────────────────────────────────────────
        $students = $school->studentCount();
        $teachers = $school->teacherCount();
        $admins   = $school->users()->role('admin')->count();

        // ── Payment stats ─────────────────────────────────────────────────────
        $paymentsBase = $school->payments()->where('year', $year)->where('month', $month);

        $paymentStats = [
            'paid'    => (clone $paymentsBase)->where('status', 'paid')->count(),
            'unpaid'  => (clone $paymentsBase)->where('status', 'unpaid')->count(),
            'partial' => (clone $paymentsBase)->where('status', 'partial')->count(),
            'revenue' => (clone $paymentsBase)->whereIn('status', ['paid', 'partial'])->sum('amount'),
        ];

        // ── File stats ────────────────────────────────────────────────────────
        $fileStats = $school->files()
            ->selectRaw('grade_id, count(*) as total, sum(size_bytes) as total_bytes')
            ->groupBy('grade_id')
            ->with('grade:id,name')
            ->get()
            ->map(fn ($row) => [
                'grade'       => $row->grade?->name ?? 'School-wide',
                'total_files' => $row->total,
                'total_mb'    => round($row->total_bytes / 1024 / 1024, 2),
            ]);

        $totalFiles   = $school->files()->count();
        $totalFileMb  = round($school->files()->sum('size_bytes') / 1024 / 1024, 2);

        // ── Activity stats ────────────────────────────────────────────────────
        $activeUsers24h = ActivityLog::where('school_id', $school->id)
            ->where('action', 'user.login')
            ->where('created_at', '>=', $now->copy()->subHours(24))
            ->distinct('user_id')
            ->count('user_id');

        $activeUsers7d = ActivityLog::where('school_id', $school->id)
            ->where('action', 'user.login')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');

        // ── Course-school: unpaid students for current month ──────────────────
        $courseUnpaidCount = 0;
        $courseUnpaidSample = [];
        if (in_array($school->school_type, ['course', 'both'], true)) {
            $unpaidEnrollments = CourseEnrollment::where('school_id', $school->id)
                ->where('status', 'active')
                ->with(['studentProfile.user', 'courseClass'])
                ->whereDoesntHave('payments', function ($q) use ($year, $month) {
                    $q->where('month', $month)
                      ->where('year',  $year)
                      ->whereIn('status', ['paid', 'waived']);
                })
                ->get();

            $courseUnpaidCount  = $unpaidEnrollments->count();
            $courseUnpaidSample = $unpaidEnrollments->take(5)->map(fn ($e) => [
                'student_name'    => $e->studentProfile?->user?->name ?? '—',
                'class_name'      => $e->courseClass?->name ?? '—',
                'expected_amount' => (float) ($e->monthly_fee_override ?? $e->courseClass?->monthly_fee ?? 0),
            ])->values();
        }

        return response()->json([
            'counts' => [
                'students'   => $students,
                'teachers'   => $teachers,
                'admins'     => $admins,
                'grades'     => $school->grades()->count(),
                'classrooms' => $school->classrooms()->count(),
            ],
            'payments_this_month' => $paymentStats,
            'files' => [
                'total'        => $totalFiles,
                'total_mb'     => $totalFileMb,
                'by_grade'     => $fileStats,
            ],
            'activity' => [
                'active_users_24h' => $activeUsers24h,
                'active_users_7d'  => $activeUsers7d,
            ],
            'course_unpaid' => [
                'count'  => $courseUnpaidCount,
                'sample' => $courseUnpaidSample,
                'month'  => $month,
                'year'   => $year,
            ],
            'plan'          => $school->plan,
            'school_status' => $school->status,
            'trial_ends_at' => $school->trial_ends_at,
        ]);
    }
}
