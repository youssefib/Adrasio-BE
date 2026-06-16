<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\PayrollEntry;
use App\Models\School;
use App\Models\User;
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

        // Fetch staff to generate payroll for
        $staffQuery = User::where('school_id', $schoolId)
            ->whereNotIn('role', ['student'])
            ->whereNotNull('base_salary')
            ->where('base_salary', '>', 0);

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

            [$base, $variable] = $this->calculateSalary($user, $month, $year, $school);

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
     * base_plus_per_class:
     *   variable = active_classes_count × salary_variable_rate (MAD/class)
     *
     * base_plus_per_student — two sub-modes:
     *   salary_rate_is_percentage = false  → fixed MAD per active student
     *     variable = Σ_per_class ( active_students × rate )
     *
     *   salary_rate_is_percentage = true   → percentage of class monthly_fee per student
     *     variable = Σ_per_class ( active_students × class.monthly_fee × rate / 100 )
     *     This means the teacher earns X% of each class's revenue they generate.
     */
    private function calculateSalary(User $user, int $month, int $year, School $school): array
    {
        $base = (float) ($user->base_salary ?? 0);

        if (
            $user->salary_type === 'fixed'
            || ! ($user->salary_variable_rate > 0)
            || ! in_array($school->school_type, ['course', 'both'], true)
        ) {
            return [$base, 0.0];
        }

        $rate = (float) $user->salary_variable_rate;

        if ($user->salary_type === 'base_plus_per_class') {
            $classCount = CourseClass::where('school_id', $school->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'active')
                ->count();
            return [$base, $classCount * $rate];
        }

        // base_plus_per_student — get each active class the teacher owns
        $classes = CourseClass::where('school_id', $school->id)
            ->where('teacher_id', $user->id)
            ->where('status', 'active')
            ->get(['id', 'monthly_fee']);

        $variable = 0.0;

        foreach ($classes as $class) {
            $activeStudents = CourseEnrollment::where('course_class_id', $class->id)
                ->where('status', 'active')
                ->count();

            if ($user->salary_rate_is_percentage) {
                // X % of the class monthly_fee per enrolled student
                $variable += $activeStudents * ((float) $class->monthly_fee) * ($rate / 100.0);
            } else {
                // Fixed MAD amount per enrolled student
                $variable += $activeStudents * $rate;
            }
        }

        return [$base, round($variable, 2)];
    }

    /**
     * Return a preview of variable salary for a teacher given current classes/students.
     * Used by the frontend salary settings form for live preview.
     */
    public function salaryPreview(Request $r): JsonResponse
    {
        $r->validate([
            'user_id'                   => 'required|exists:users,id',
            'salary_type'               => 'required|in:fixed,base_plus_per_class,base_plus_per_student',
            'base_salary'               => 'required|numeric|min:0',
            'salary_variable_rate'      => 'nullable|numeric|min:0',
            'salary_rate_is_percentage' => 'nullable|boolean',
        ]);

        $user = User::where('id', $r->user_id)
            ->where('school_id', $this->schoolId())
            ->firstOrFail();

        $school = $this->school();

        // Temporarily hydrate user with preview values
        $user->salary_type               = $r->salary_type;
        $user->base_salary               = (float) $r->base_salary;
        $user->salary_variable_rate      = (float) ($r->salary_variable_rate ?? 0);
        $user->salary_rate_is_percentage = (bool)  ($r->salary_rate_is_percentage ?? false);

        [$base, $variable] = $this->calculateSalary($user, now()->month, now()->year, $school);

        // Also return class-by-class breakdown for the UI
        $breakdown = [];
        if ($r->salary_type === 'base_plus_per_student' && $r->salary_variable_rate > 0) {
            $classes = CourseClass::where('school_id', $school->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'active')
                ->get(['id', 'name', 'monthly_fee']);

            foreach ($classes as $class) {
                $students = CourseEnrollment::where('course_class_id', $class->id)
                    ->where('status', 'active')
                    ->count();

                $rate = (float) $r->salary_variable_rate;
                if ($r->salary_rate_is_percentage) {
                    $classVar = $students * ((float) $class->monthly_fee) * ($rate / 100.0);
                    $pct      = $rate;
                } else {
                    $classVar = $students * $rate;
                    // Back-calculate equivalent % from this class's fee
                    $pct = $class->monthly_fee > 0
                        ? round($rate / (float) $class->monthly_fee * 100, 1)
                        : null;
                }

                $breakdown[] = [
                    'class_name'   => $class->name,
                    'monthly_fee'  => (float) $class->monthly_fee,
                    'students'     => $students,
                    'class_variable'  => round($classVar, 2),
                    'equivalent_pct'  => $pct,
                ];
            }
        } elseif ($r->salary_type === 'base_plus_per_class' && $r->salary_variable_rate > 0) {
            $classes = CourseClass::where('school_id', $school->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'active')
                ->get(['id', 'name', 'monthly_fee']);

            foreach ($classes as $class) {
                $breakdown[] = [
                    'class_name'     => $class->name,
                    'monthly_fee'    => (float) $class->monthly_fee,
                    'class_variable' => (float) $r->salary_variable_rate,
                    'students'       => null,
                    'equivalent_pct' => null,
                ];
            }
        }

        return response()->json([
            'base_amount'     => $base,
            'variable_amount' => $variable,
            'total_amount'    => $base + $variable,
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
