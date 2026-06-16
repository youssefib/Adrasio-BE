<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\TeacherCommission;
use App\Models\User;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevenueController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /school/course/revenue?year=YYYY&month=MM
     *
     * Monthly revenue summary: total income, commissions, net, by class, by teacher.
     */
    public function summary(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $year   = (int) ($request->year  ?? now()->year);
        $month  = (int) ($request->month ?? now()->month);

        $byClass   = [];
        $byTeacher = [];

        $classes = $school->courseClasses()
            ->with(['course', 'teacher:id,name', 'enrollments.monthlyStatuses', 'commissions'])
            ->get();

        $totalIncome      = 0;
        $totalCommissions = 0;

        foreach ($classes as $class) {
            $activeStudents = $class->enrollments->filter(
                fn (CourseEnrollment $e) => $e->isActiveForMonth($year, $month)
            );

            $classIncome = $activeStudents->sum(fn (CourseEnrollment $e) => $e->effectiveFee());
            $totalIncome += $classIncome;

            // Commission for this class
            $commission = $this->calculateCommission($class, $activeStudents->count(), $year, $month);
            $totalCommissions += $commission['amount'];

            $byClass[] = [
                'class_id'       => $class->id,
                'class_name'     => $class->name,
                'course'         => $class->course?->name,
                'teacher_id'     => $class->teacher_id,
                'teacher_name'   => $class->teacher?->name,
                'active_students'=> $activeStudents->count(),
                'income'         => round($classIncome, 2),
                'commission'     => round($commission['amount'], 2),
                'commission_type'=> $commission['type'],
                'net'            => round($classIncome - $commission['amount'], 2),
            ];

            if ($class->teacher_id) {
                $tid = $class->teacher_id;
                if (! isset($byTeacher[$tid])) {
                    $byTeacher[$tid] = [
                        'teacher_id'   => $tid,
                        'teacher_name' => $class->teacher?->name,
                        'income'       => 0,
                        'commission'   => 0,
                        'active_students' => 0,
                    ];
                }
                $byTeacher[$tid]['income']          += $classIncome;
                $byTeacher[$tid]['commission']       += $commission['amount'];
                $byTeacher[$tid]['active_students']  += $activeStudents->count();
            }
        }

        // Additional charges paid this month
        $additionalIncome = $school->additionalCharges()
            ->where('status', 'paid')
            ->whereYear('charge_date', $year)
            ->whereMonth('charge_date', $month)
            ->sum('amount');

        $totalIncome += $additionalIncome;

        foreach ($byTeacher as &$t) {
            $t['net']    = round($t['income'] - $t['commission'], 2);
            $t['income'] = round($t['income'], 2);
            $t['commission'] = round($t['commission'], 2);
        }

        return response()->json([
            'year'              => $year,
            'month'             => $month,
            'total_income'      => round($totalIncome, 2),
            'total_commissions' => round($totalCommissions, 2),
            'net_income'        => round($totalIncome - $totalCommissions, 2),
            'additional_income' => round($additionalIncome, 2),
            'by_class'          => $byClass,
            'by_teacher'        => array_values($byTeacher),
        ]);
    }

    /**
     * GET /school/course/revenue/export?year=YYYY&month=MM&type=overall|teacher&teacher_id=N
     * Returns a CSV file download.
     */
    public function export(Request $request): Response
    {
        $school = $this->currentSchool($request);
        $year   = (int) ($request->year  ?? now()->year);
        $month  = (int) ($request->month ?? now()->month);
        $type   = $request->type ?? 'overall';

        if ($type === 'teacher' && $request->teacher_id) {
            return $this->exportTeacher($school, (int) $request->teacher_id, $year, $month);
        }

        return $this->exportOverall($school, $year, $month);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function calculateCommission(CourseClass $class, int $activeCount, int $year, int $month): array
    {
        if (! $class->teacher_id) {
            return ['amount' => 0, 'type' => null];
        }

        $periodDate = \Carbon\Carbon::createFromDate($year, $month, 1)->toDateString();

        // Class-specific commission first, then teacher default
        $rule = TeacherCommission::where('school_id', $class->school_id)
            ->where('teacher_id', $class->teacher_id)
            ->where(fn ($q) => $q->where('course_class_id', $class->id)->orWhereNull('course_class_id'))
            ->where('effective_from', '<=', $periodDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $periodDate))
            ->orderByRaw('course_class_id IS NULL ASC') // class-specific first
            ->first();

        if (! $rule) {
            return ['amount' => 0, 'type' => null];
        }

        $amount = match ($rule->commission_type) {
            'per_student'   => $rule->amount * $activeCount,
            'per_class'     => $activeCount > 0 ? $rule->amount : 0,
            'fixed_monthly' => $rule->amount,
            default         => 0,
        };

        return ['amount' => $amount, 'type' => $rule->commission_type];
    }

    private function exportOverall($school, int $year, int $month): Response
    {
        $classes = $school->courseClasses()
            ->with(['course', 'teacher:id,name', 'enrollments.monthlyStatuses', 'commissions'])
            ->get();

        $rows = [['Classe', 'Cours', 'Professeur', 'Élèves actifs', 'Revenu (MAD)', 'Commission (MAD)', 'Net (MAD)']];

        foreach ($classes as $class) {
            $active = $class->enrollments->filter(fn (CourseEnrollment $e) => $e->isActiveForMonth($year, $month));
            $income = $active->sum(fn (CourseEnrollment $e) => $e->effectiveFee());
            $comm   = $this->calculateCommission($class, $active->count(), $year, $month);

            $rows[] = [
                $class->name,
                $class->course?->name ?? '—',
                $class->teacher?->name ?? '—',
                $active->count(),
                number_format($income, 2),
                number_format($comm['amount'], 2),
                number_format($income - $comm['amount'], 2),
            ];
        }

        return $this->csvResponse($rows, "revenue_{$year}_{$month}.csv");
    }

    private function exportTeacher($school, int $teacherId, int $year, int $month): Response
    {
        $teacher = User::where('id', $teacherId)->where('school_id', $school->id)->firstOrFail();

        $classes = $school->courseClasses()
            ->where('teacher_id', $teacherId)
            ->with(['course', 'enrollments.monthlyStatuses', 'enrollments.studentProfile.user'])
            ->get();

        $rows = [['Classe', 'Cours', 'Élève', 'Statut', 'Frais (MAD)']];

        foreach ($classes as $class) {
            foreach ($class->enrollments as $enrollment) {
                $active = $enrollment->isActiveForMonth($year, $month);
                $rows[] = [
                    $class->name,
                    $class->course?->name ?? '—',
                    $enrollment->studentProfile?->user?->name ?? '—',
                    $active ? 'Actif' : 'Inactif',
                    $active ? number_format($enrollment->effectiveFee(), 2) : '0.00',
                ];
            }
        }

        $totalIncome = 0;
        $totalComm   = 0;
        foreach ($classes as $class) {
            $activeCount = $class->enrollments->filter(fn ($e) => $e->isActiveForMonth($year, $month))->count();
            $income = $class->enrollments
                ->filter(fn ($e) => $e->isActiveForMonth($year, $month))
                ->sum(fn ($e) => $e->effectiveFee());
            $comm = $this->calculateCommission($class, $activeCount, $year, $month);
            $totalIncome += $income;
            $totalComm   += $comm['amount'];
        }

        $rows[] = [];
        $rows[] = ['Total revenu', number_format($totalIncome, 2) . ' MAD', '', '', ''];
        $rows[] = ['Commission', number_format($totalComm, 2) . ' MAD', '', '', ''];
        $rows[] = ['Net', number_format($totalIncome - $totalComm, 2) . ' MAD', '', '', ''];

        return $this->csvResponse($rows, "teacher_{$teacherId}_revenue_{$year}_{$month}.csv");
    }

    private function csvResponse(array $rows, string $filename): Response
    {
        $output = fopen('php://temp', 'r+');
        // BOM for Excel UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
