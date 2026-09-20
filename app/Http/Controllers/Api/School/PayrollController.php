<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\CourseClass;
use App\Models\PayrollEntry;
use App\Models\School;
use App\Models\User;
use App\Services\CourseRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    private function school(): School
    {
        return Auth::user()->school;
    }

    private function schoolId(): int
    {
        return Auth::user()->school_id;
    }

    // ── List entries for a given month/year, optionally filtered by role ──────

    public function index(Request $r): JsonResponse
    {
        $r->validate([
            'month'     => 'required|integer|min:1|max:12',
            'year'      => 'required|integer|min:2000|max:2100',
            'role'      => 'nullable|in:teacher,admin,school_owner',
            'user_id'   => 'nullable|exists:users,id',
        ]);

        $entries = PayrollEntry::where('school_id', $this->schoolId())
            ->where('month', (int) $r->month)
            ->where('year',  (int) $r->year)
            ->with('user:id,name,role,base_salary,salary_type,salary_variable_rate')
            ->when($r->user_id, fn ($q) => $q->where('user_id', $r->user_id))
            ->when($r->role, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $r->role)))
            ->orderBy('user_id')
            ->orderBy('type')
            ->get();

        return response()->json($entries);
    }

    // ── Auto-generate salary entries for all staff of a given role/month ──────

    public function generate(Request $r): JsonResponse
    {
        $r->validate([
            'month'  => 'required|integer|min:1|max:12',
            'year'   => 'required|integer|min:2000|max:2100',
            'role'   => 'nullable|in:teacher,admin,school_owner',
        ]);

        $month    = (int) $r->month;
        $year     = (int) $r->year;
        $schoolId = $this->schoolId();
        $school   = $this->school();
        $createdBy = Auth::id();

        $svc = new CourseRevenueService($schoolId);

        // Consider all staff (teachers may earn only a % with no base); zero-total
        // staff are skipped below.
        $staffQuery = User::where('school_id', $schoolId)
            ->whereNotIn('role', ['student']);

        if ($r->filled('role')) {
            $staffQuery->where('role', $r->role);
        }

        $staffList = $staffQuery->get();
        $created   = [];
        $skipped   = [];

        foreach ($staffList as $user) {
            // Skip if salary entry already exists for this month
            $existing = PayrollEntry::where([
                'school_id' => $schoolId,
                'user_id'   => $user->id,
                'month'     => $month,
                'year'      => $year,
                'type'      => 'salary',
            ])->exists();

            if ($existing) {
                $skipped[] = $user->id;
                continue;
            }

            [$base, $variable] = $this->calculateSalary($user, $month, $year, $school, $svc);

            // Nothing to pay this month → don't create an empty payslip.
            if ($base + $variable <= 0) {
                $skipped[] = $user->id;
                continue;
            }

            $entry = PayrollEntry::create([
                'school_id'       => $schoolId,
                'user_id'         => $user->id,
                'month'           => $month,
                'year'            => $year,
                'type'            => 'salary',
                'base_amount'     => $base,
                'variable_amount' => $variable,
                'total_amount'    => $base + $variable,
                'description'     => "Salaire {$this->monthName($month)} {$year}",
                'status'          => 'pending',
                'created_by'      => $createdBy,
            ]);

            $entry->load('user:id,name,role,base_salary,salary_type,salary_variable_rate');
            $created[] = $entry;
        }

        return response()->json([
            'created' => $created,
            'skipped_count' => count($skipped),
            'message' => count($created) . ' entrée(s) créée(s), ' . count($skipped) . ' ignorée(s) (déjà existantes)',
        ]);
    }

    // ── Create a manual entry (advance or bonus) ──────────────────────────────

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'user_id'     => 'required|exists:users,id',
            'month'       => 'required|integer|min:1|max:12',
            'year'        => 'required|integer|min:2000|max:2100',
            'type'        => 'required|in:advance,bonus',
            'total_amount'=> 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'status'      => 'sometimes|in:pending,paid,cancelled',
        ]);

        // Ensure user belongs to this school
        $user = User::where('id', $data['user_id'])
            ->where('school_id', $this->schoolId())
            ->firstOrFail();

        $entry = PayrollEntry::create([
            'school_id'       => $this->schoolId(),
            'user_id'         => $user->id,
            'month'           => (int) $data['month'],
            'year'            => (int) $data['year'],
            'type'            => $data['type'],
            'base_amount'     => $data['total_amount'],
            'variable_amount' => 0,
            'total_amount'    => $data['total_amount'],
            'description'     => $data['description'] ?? null,
            'status'          => $data['status'] ?? 'pending',
            'created_by'      => Auth::id(),
        ]);

        $entry->load('user:id,name,role');

        return response()->json($entry, 201);
    }

    // ── Update an entry (amount, status, description) ─────────────────────────

    public function update(Request $r, PayrollEntry $payrollEntry): JsonResponse
    {
        abort_if($payrollEntry->school_id !== $this->schoolId(), 403);

        $data = $r->validate([
            'total_amount' => 'sometimes|numeric|min:0',
            'base_amount'  => 'sometimes|numeric|min:0',
            'variable_amount' => 'sometimes|numeric|min:0',
            'description'  => 'sometimes|nullable|string|max:255',
            'status'       => 'sometimes|in:pending,paid,cancelled',
            'paid_at'      => 'sometimes|nullable|date',
        ]);

        // Recalculate total if individual parts changed
        if (isset($data['base_amount']) || isset($data['variable_amount'])) {
            $base     = $data['base_amount']     ?? $payrollEntry->base_amount;
            $variable = $data['variable_amount'] ?? $payrollEntry->variable_amount;
            $data['total_amount'] = $base + $variable;
        } elseif (isset($data['total_amount']) && $payrollEntry->type !== 'salary') {
            $data['base_amount'] = $data['total_amount'];
        }

        if (isset($data['status']) && $data['status'] === 'paid' && ! $payrollEntry->paid_at) {
            $data['paid_at'] = now();
        }

        $payrollEntry->update($data);
        $payrollEntry->load('user:id,name,role');

        return response()->json($payrollEntry);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(PayrollEntry $payrollEntry): JsonResponse
    {
        abort_if($payrollEntry->school_id !== $this->schoolId(), 403);
        $payrollEntry->delete();

        return response()->json(null, 204);
    }

    // ── Mark all pending entries as paid for a month ──────────────────────────

    public function markMonthPaid(Request $r): JsonResponse
    {
        $r->validate([
            'month'   => 'required|integer|min:1|max:12',
            'year'    => 'required|integer|min:2000|max:2100',
            'role'    => 'nullable|in:teacher,admin,school_owner',
            'user_id' => 'nullable|integer',
        ]);

        $query = PayrollEntry::where('school_id', $this->schoolId())
            ->where('month', (int) $r->month)
            ->where('year',  (int) $r->year)
            ->where('status', 'pending');

        if ($r->filled('user_id')) {
            $query->where('user_id', $r->user_id);
        } elseif ($r->filled('role')) {
            $query->whereHas('user', fn ($u) => $u->where('role', $r->role));
        }

        $count = $query->update(['status' => 'paid', 'paid_at' => now()]);

        return response()->json(['paid_count' => $count]);
    }

    // ── CSV export for a given month ──────────────────────────────────────────

    public function exportCsv(Request $r)
    {
        $r->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000|max:2100',
            'role'  => 'nullable|in:teacher,admin,school_owner',
        ]);

        $month    = (int) $r->month;
        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $entries = PayrollEntry::where('school_id', $schoolId)
            ->where('month', $month)
            ->where('year',  $year)
            ->with('user:id,name,role,base_salary,salary_type')
            ->when($r->role, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $r->role)))
            ->orderBy('user_id')
            ->orderBy('type')
            ->get();

        $typeLabels = ['salary' => 'Salaire', 'advance' => 'Avance', 'bonus' => 'Prime'];
        $statusLabels = ['pending' => 'En attente', 'paid' => 'Payé', 'cancelled' => 'Annulé'];
        $roleLabels = ['teacher' => 'Enseignant', 'admin' => 'Administrateur', 'school_owner' => 'Directeur'];

        $rows = [
            ['Bulletin de Paie - ' . $this->monthName($month) . ' ' . $year],
            [],
            ['Nom', 'Rôle', 'Type', 'Salaire de base', 'Part variable', 'Total', 'Statut', 'Payé le', 'Description'],
        ];

        foreach ($entries as $e) {
            $rows[] = [
                $e->user?->name ?? '—',
                $roleLabels[$e->user?->role ?? ''] ?? ($e->user?->role ?? '—'),
                $typeLabels[$e->type] ?? $e->type,
                number_format((float) $e->base_amount, 2, '.', ''),
                number_format((float) $e->variable_amount, 2, '.', ''),
                number_format((float) $e->total_amount, 2, '.', ''),
                $statusLabels[$e->status] ?? $e->status,
                $e->paid_at ? $e->paid_at->format('d/m/Y') : '',
                $e->description ?? '',
            ];
        }

        $rows[] = [];
        $total = $entries->sum(fn ($e) => (float) $e->total_amount);
        $rows[] = ['', '', 'TOTAL', '', '', number_format($total, 2, '.', ''), '', '', ''];

        $csv = implode("\n", array_map(
            fn ($row) => implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $row)),
            $rows
        ));

        $filename = 'paie_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Calculate base + variable salary for a user for a given month.
     * Returns [base, variable].
     *
     * Unified model: variable = Σ over the teacher's active classes of
     * (class monthly revenue × the class's teacher_share_pct). Class revenue
     * includes both individual enrollments and pack members' per-class shares.
     */
    private function calculateSalary(User $user, int $month, int $year, School $school, CourseRevenueService $svc): array
    {
        $base = (float) ($user->base_salary ?? 0);

        if (! in_array($school->school_type, ['course', 'both'], true)) {
            return [$base, 0.0];
        }

        $classes = CourseClass::where('school_id', $school->id)
            ->where('teacher_id', $user->id)
            ->where('status', 'active')
            ->with(['enrollments.monthlyStatuses', 'enrollments.groupEnrollment'])
            ->get();

        $variable = 0.0;
        foreach ($classes as $class) {
            $income = $svc->classIncome($class, $year, $month);
            $variable += $svc->teacherShare($class, $income);
        }

        return [$base, round($variable, 2)];
    }

    /**
     * Preview a teacher's pay for the current month under the base + % model.
     * variable = Σ (class revenue × class teacher_share_pct). Used by the
     * frontend salary form for a live preview.
     */
    public function salaryPreview(Request $r): JsonResponse
    {
        $r->validate([
            'user_id'     => 'required|exists:users,id',
            'base_salary' => 'nullable|numeric|min:0',
        ]);

        $user = User::where('id', $r->user_id)
            ->where('school_id', $this->schoolId())
            ->firstOrFail();

        $school = $this->school();
        $svc    = new CourseRevenueService($this->schoolId());
        $year   = now()->year;
        $month  = now()->month;

        $base = $r->filled('base_salary') ? (float) $r->base_salary : (float) ($user->base_salary ?? 0);

        $classes = CourseClass::where('school_id', $school->id)
            ->where('teacher_id', $user->id)
            ->where('status', 'active')
            ->with(['enrollments.monthlyStatuses', 'enrollments.groupEnrollment'])
            ->get();

        $variable  = 0.0;
        $breakdown = [];
        foreach ($classes as $class) {
            $income = $svc->classIncome($class, $year, $month);
            $share  = $svc->teacherShare($class, $income);
            $variable += $share;

            $breakdown[] = [
                'class_name'        => $class->name,
                'monthly_income'    => round($income, 2),
                'teacher_share_pct' => (float) $class->teacher_share_pct,
                'teacher_share'     => $share,
            ];
        }

        return response()->json([
            'base_amount'     => round($base, 2),
            'variable_amount' => round($variable, 2),
            'total_amount'    => round($base + $variable, 2),
            'breakdown'       => $breakdown,
        ]);
    }

    private function monthName(int $month): string
    {
        $names = ['Janvier','Février','Mars','Avril','Mai','Juin',
                  'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        return $names[$month - 1] ?? (string) $month;
    }
}
