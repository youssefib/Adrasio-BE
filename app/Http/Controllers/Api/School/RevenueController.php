<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\ClassGroupPayment;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Models\PayrollEntry;
use App\Models\StaffExpense;
use App\Models\User;
use App\Services\CourseRevenueService;
use App\Traits\ScopedToSchool;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevenueController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /school/course/revenue?year=YYYY&month=MM
     *
     * Monthly revenue summary. A class's income includes both individual
     * enrollments and pack members' per-class shares. The teacher's cut is
     * class income × the class's teacher_share_pct.
     */
    public function summary(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $year   = (int) ($request->year  ?? now()->year);
        $month  = (int) ($request->month ?? now()->month);

        $svc     = new CourseRevenueService($school->id);
        $classes = $this->loadClasses($school);

        $byClass   = [];
        $byTeacher = [];
        $totalIncome = 0;
        $totalShares = 0;

        foreach ($classes as $class) {
            $active = $class->enrollments->filter(
                fn (CourseEnrollment $e) => $e->isActiveForMonth($year, $month)
            );

            $income = (float) $active->sum(fn (CourseEnrollment $e) => $svc->enrollmentRevenue($e));
            $share  = $svc->teacherShare($class, $income);

            $totalIncome += $income;
            $totalShares += $share;

            $byClass[] = [
                'class_id'          => $class->id,
                'class_name'        => $class->name,
                'course'            => $class->course?->name,
                'teacher_id'        => $class->teacher_id,
                'teacher_name'      => $class->teacher?->name,
                'teacher_share_pct' => (float) $class->teacher_share_pct,
                'active_students'   => $active->count(),
                'income'            => round($income, 2),
                'commission'        => $share,       // teacher's cut (kept key for the UI)
                'commission_type'   => null,
                'net'               => round($income - $share, 2),
            ];

            if ($class->teacher_id) {
                $tid = $class->teacher_id;
                if (! isset($byTeacher[$tid])) {
                    $byTeacher[$tid] = [
                        'teacher_id'      => $tid,
                        'teacher_name'    => $class->teacher?->name,
                        'income'          => 0,
                        'commission'      => 0,
                        'active_students' => 0,
                    ];
                }
                $byTeacher[$tid]['income']          += $income;
                $byTeacher[$tid]['commission']      += $share;
                $byTeacher[$tid]['active_students'] += $active->count();
            }
        }

        // Additional charges for this month (one-off fees). Only paid ones count
        // toward income; the full list is returned so the UI can show them.
        $charges = $school->additionalCharges()
            ->with('studentProfile.user')
            ->whereYear('charge_date', $year)
            ->whereMonth('charge_date', $month)
            ->orderByDesc('charge_date')
            ->get();

        $additionalIncome  = (float) $charges->where('status', 'paid')->sum('amount');
        $additionalPending = (float) $charges->where('status', 'pending')->sum('amount');

        $additionalCharges = $charges->map(fn ($c) => [
            'id'          => $c->id,
            'description' => $c->description,
            'student'     => $c->studentProfile?->user?->name,
            'amount'      => (float) $c->amount,
            'status'      => $c->status,
            'charge_date' => $c->charge_date?->toDateString(),
        ])->values();

        $totalIncome += $additionalIncome;

        foreach ($byTeacher as &$t) {
            $t['net']        = round($t['income'] - $t['commission'], 2);
            $t['income']     = round($t['income'], 2);
            $t['commission'] = round($t['commission'], 2);
        }

        return response()->json([
            'year'              => $year,
            'month'             => $month,
            'total_income'      => round($totalIncome, 2),
            'total_commissions' => round($totalShares, 2),
            'net_income'         => round($totalIncome - $totalShares, 2),
            'additional_income'  => round($additionalIncome, 2),
            'additional_pending' => round($additionalPending, 2),
            'additional_charges' => $additionalCharges,
            'by_class'           => $byClass,
            'by_teacher'         => array_values($byTeacher),
        ]);
    }

    /**
     * GET /school/course/revenue/daily?year=YYYY&month=MM
     *
     * Cash-flow view for the month: money collected per day (course + pack
     * payments + paid additional charges) and money spent per day (staff
     * expenses). Grouped by the actual transaction date.
     */
    public function daily(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $year   = (int) ($request->year  ?? now()->year);
        $month  = (int) ($request->month ?? now()->month);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // ── Payments collected this month ──────────────────────────────────
        $payments = collect();

        CoursePayment::where('school_id', $school->id)
            ->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->get(['paid_at', 'amount'])
            ->each(fn ($p) => $payments->push(['date' => Carbon::parse($p->paid_at)->format('Y-m-d'), 'amount' => (float) $p->amount]));

        ClassGroupPayment::where('school_id', $school->id)
            ->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->get(['paid_at', 'amount'])
            ->each(fn ($p) => $payments->push(['date' => Carbon::parse($p->paid_at)->format('Y-m-d'), 'amount' => (float) $p->amount]));

        AdditionalCharge::where('school_id', $school->id)
            ->where('status', 'paid')
            ->get(['paid_at', 'charge_date', 'amount'])
            ->each(function ($c) use ($payments, $start, $end) {
                $date = $c->paid_at ? Carbon::parse($c->paid_at) : ($c->charge_date ? Carbon::parse($c->charge_date) : null);
                if ($date && $date->betweenIncluded($start, $end)) {
                    $payments->push(['date' => $date->format('Y-m-d'), 'amount' => (float) $c->amount]);
                }
            });

        $paymentsByDay = $payments->groupBy('date')->map(fn ($grp, $date) => [
            'date'   => $date,
            'amount' => round($grp->sum('amount'), 2),
            'count'  => $grp->count(),
        ])->sortKeys()->values();

        // ── Spending this month (staff expenses) ───────────────────────────
        $expensesByDay = StaffExpense::where('school_id', $school->id)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->get(['expense_date', 'amount'])
            ->groupBy(fn ($e) => Carbon::parse($e->expense_date)->format('Y-m-d'))
            ->map(fn ($grp, $date) => [
                'date'   => $date,
                'amount' => round($grp->sum(fn ($e) => (float) $e->amount), 2),
                'count'  => $grp->count(),
            ])->sortKeys()->values();

        $totalPayments = round($paymentsByDay->sum('amount'), 2);
        $totalExpenses = round($expensesByDay->sum('amount'), 2);

        return response()->json([
            'year'            => $year,
            'month'           => $month,
            'payments_by_day' => $paymentsByDay,
            'expenses_by_day' => $expensesByDay,
            'total_payments'  => $totalPayments,
            'total_expenses'  => $totalExpenses,
            'net'             => round($totalPayments - $totalExpenses, 2),
        ]);
    }

    /**
     * GET /school/course/revenue/monthly?year=YYYY&month=MM
     *
     * Itemised monthly ledger: every payment collected and every expense
     * (staff expenses + teacher payroll) for the month, as two lists.
     */
    public function monthly(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);
        $year   = (int) ($request->year  ?? now()->year);
        $month  = (int) ($request->month ?? now()->month);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->endOfMonth();

        // ── Payments collected this month ──────────────────────────────────
        $payments = collect();

        CoursePayment::where('school_id', $school->id)
            ->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->with(['enrollment.studentProfile.user:id,name', 'enrollment.courseClass:id,name'])
            ->get()
            ->each(fn ($p) => $payments->push([
                'date'   => Carbon::parse($p->paid_at)->format('Y-m-d'),
                'type'   => 'course',
                'label'  => $p->enrollment?->studentProfile?->user?->name ?? '—',
                'detail' => $p->enrollment?->courseClass?->name,
                'amount' => (float) $p->amount,
            ]));

        ClassGroupPayment::where('school_id', $school->id)
            ->where('status', 'paid')->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->with(['enrollment.studentProfile.user:id,name', 'enrollment.group:id,name'])
            ->get()
            ->each(fn ($p) => $payments->push([
                'date'   => Carbon::parse($p->paid_at)->format('Y-m-d'),
                'type'   => 'pack',
                'label'  => $p->enrollment?->studentProfile?->user?->name ?? '—',
                'detail' => $p->enrollment?->group?->name,
                'amount' => (float) $p->amount,
            ]));

        AdditionalCharge::where('school_id', $school->id)
            ->where('status', 'paid')
            ->with('studentProfile.user:id,name')
            ->get()
            ->each(function ($c) use ($payments, $start, $end) {
                $date = $c->paid_at ? Carbon::parse($c->paid_at) : ($c->charge_date ? Carbon::parse($c->charge_date) : null);
                if ($date && $date->betweenIncluded($start, $end)) {
                    $payments->push([
                        'date'   => $date->format('Y-m-d'),
                        'type'   => 'charge',
                        'label'  => $c->description,
                        'detail' => $c->studentProfile?->user?->name,
                        'amount' => (float) $c->amount,
                    ]);
                }
            });

        $payments = $payments->sortBy('date')->values();

        // ── Spending this month: staff expenses + teacher payroll ──────────
        // Salary-category expenses are excluded here because payroll below
        // already covers salaries (avoids double counting).
        $spending = collect();

        StaffExpense::where('school_id', $school->id)
            ->whereYear('expense_date', $year)->whereMonth('expense_date', $month)
            ->whereNotIn('category', ['salary', 'salary_advance'])
            ->get()
            ->each(fn ($e) => $spending->push([
                'date'   => Carbon::parse($e->expense_date)->format('Y-m-d'),
                'type'   => 'expense',
                'label'  => $e->description ?: $this->expenseCategoryLabel($e->category),
                'detail' => $this->expenseCategoryLabel($e->category),
                'amount' => (float) $e->amount,
            ]));

        $payrollLabels = ['salary' => 'Salaire', 'advance' => 'Avance', 'bonus' => 'Prime'];
        PayrollEntry::where('school_id', $school->id)
            ->where('year', $year)->where('month', $month)
            ->where('status', '!=', 'cancelled')
            ->with('user:id,name')
            ->get()
            ->each(fn ($e) => $spending->push([
                'date'   => null, // payroll is monthly, no specific day
                'type'   => 'payroll',
                'label'  => $e->user?->name ?? '—',
                'detail' => $payrollLabels[$e->type] ?? $e->type,
                'amount' => (float) $e->total_amount,
            ]));

        $spending = $spending->sortBy(fn ($s) => $s['date'] ?? '9999-99-99')->values();

        $totalPayments = round($payments->sum('amount'), 2);
        $totalSpending = round($spending->sum('amount'), 2);

        return response()->json([
            'year'           => $year,
            'month'          => $month,
            'payments'       => $payments,
            'spending'       => $spending,
            'total_payments' => $totalPayments,
            'total_spending' => $totalSpending,
            'net'            => round($totalPayments - $totalSpending, 2),
        ]);
    }

    private function expenseCategoryLabel(?string $cat): string
    {
        return [
            'transport'   => 'Transport',
            'supplies'    => 'Fournitures',
            'equipment'   => 'Équipement',
            'maintenance' => 'Maintenance',
            'rent'        => 'Loyer',
            'utilities'   => 'Eau / Électricité',
            'internet'    => 'Internet / WiFi',
            'printing'    => 'Impression',
            'other'       => 'Autres',
        ][$cat] ?? ($cat ?? '—');
    }

    /**
     * GET /school/course/revenue/export?year=YYYY&month=MM&type=overall|teacher&teacher_id=N
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

    private function loadClasses($school)
    {
        return $school->courseClasses()
            ->with(['course', 'teacher:id,name', 'enrollments.monthlyStatuses', 'enrollments.groupEnrollment'])
            ->get();
    }

    private function exportOverall($school, int $year, int $month): Response
    {
        $svc     = new CourseRevenueService($school->id);
        $classes = $this->loadClasses($school);

        $rows = [['Classe', 'Cours', 'Professeur', '% Prof', 'Élèves actifs', 'Revenu (MAD)', 'Part professeur (MAD)', 'Net (MAD)']];

        foreach ($classes as $class) {
            $active = $class->enrollments->filter(fn (CourseEnrollment $e) => $e->isActiveForMonth($year, $month));
            $income = (float) $active->sum(fn (CourseEnrollment $e) => $svc->enrollmentRevenue($e));
            $share  = $svc->teacherShare($class, $income);

            $rows[] = [
                $class->name,
                $class->course?->name ?? '—',
                $class->teacher?->name ?? '—',
                number_format((float) $class->teacher_share_pct, 2) . ' %',
                $active->count(),
                number_format($income, 2),
                number_format($share, 2),
                number_format($income - $share, 2),
            ];
        }

        return $this->csvResponse($rows, "revenue_{$year}_{$month}.csv");
    }

    private function exportTeacher($school, int $teacherId, int $year, int $month): Response
    {
        $teacher = User::where('id', $teacherId)->where('school_id', $school->id)->firstOrFail();

        $svc = new CourseRevenueService($school->id);
        $classes = $school->courseClasses()
            ->where('teacher_id', $teacherId)
            ->with(['course', 'enrollments.monthlyStatuses', 'enrollments.groupEnrollment', 'enrollments.studentProfile.user'])
            ->get();

        $rows = [['Classe', 'Cours', 'Élève', 'Statut', 'Revenu élève (MAD)']];

        foreach ($classes as $class) {
            foreach ($class->enrollments as $enrollment) {
                $active = $enrollment->isActiveForMonth($year, $month);
                $rows[] = [
                    $class->name,
                    $class->course?->name ?? '—',
                    $enrollment->studentProfile?->user?->name ?? '—',
                    $active ? 'Actif' : 'Inactif',
                    $active ? number_format($svc->enrollmentRevenue($enrollment), 2) : '0.00',
                ];
            }
        }

        $totalIncome = 0;
        $totalShare  = 0;
        foreach ($classes as $class) {
            $income = $svc->classIncome($class, $year, $month);
            $totalIncome += $income;
            $totalShare  += $svc->teacherShare($class, $income);
        }

        $rows[] = [];
        $rows[] = ['Total revenu', number_format($totalIncome, 2) . ' MAD', '', '', ''];
        $rows[] = ['Part professeur', number_format($totalShare, 2) . ' MAD', '', '', ''];
        $rows[] = ['Net (école)', number_format($totalIncome - $totalShare, 2) . ' MAD', '', '', ''];

        return $this->csvResponse($rows, "teacher_{$teacherId}_revenue_{$year}_{$month}.csv");
    }

    private function csvResponse(array $rows, string $filename): Response
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel UTF-8
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
