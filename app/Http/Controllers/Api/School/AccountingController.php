<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\StaffExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    private function schoolId(): int
    {
        return Auth::user()->school_id;
    }

    // ── Monthly accounting summary ────────────────────────────────────────────

    public function monthly(Request $r): JsonResponse
    {
        $r->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $month    = (int) $r->month;
        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $data = $this->buildMonthData($schoolId, $year, $month);

        return response()->json($data);
    }

    // ── Yearly accounting summary (all 12 months) ─────────────────────────────

    public function yearly(Request $r): JsonResponse
    {
        $r->validate(['year' => 'required|integer|min:2000|max:2100']);

        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = $this->buildMonthData($schoolId, $year, $m);
        }

        // Yearly totals
        $totals = [
            'year'             => $year,
            'total_income'     => array_sum(array_column($months, 'total_income')),
            'total_payroll'    => array_sum(array_column($months, 'total_payroll')),
            'total_expenses'   => array_sum(array_column($months, 'total_expenses')),
            'total_net'        => array_sum(array_column($months, 'net')),
        ];

        return response()->json(['months' => $months, 'totals' => $totals]);
    }

    // ── Journal d'écriture for a month ────────────────────────────────────────
    // Returns accounting journal entries following French-Moroccan plan comptable

    public function journal(Request $r): JsonResponse
    {
        $r->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $month    = (int) $r->month;
        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $entries = $this->buildJournalEntries($schoolId, $year, $month);

        return response()->json([
            'month'   => $month,
            'year'    => $year,
            'entries' => $entries,
        ]);
    }

    // ── Export yearly CSV ─────────────────────────────────────────────────────

    public function exportYearly(Request $r)
    {
        $r->validate(['year' => 'required|integer|min:2000|max:2100']);

        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = $this->buildMonthData($schoolId, $year, $m);
        }

        $monthNames = ['Janvier','Février','Mars','Avril','Mai','Juin',
                       'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

        $rows = [];
        $rows[] = ['SITUATION COMPTABLE ANNUELLE ' . $year];
        $rows[] = [];
        $rows[] = ['Mois', 'Recettes (MAD)', 'Masse salariale (MAD)', 'Dépenses générales (MAD)', 'Total charges (MAD)', 'Résultat net (MAD)'];

        foreach ($months as $i => $m) {
            $rows[] = [
                $monthNames[$i],
                number_format($m['total_income'],   2, '.', ''),
                number_format($m['total_payroll'],  2, '.', ''),
                number_format($m['total_expenses'], 2, '.', ''),
                number_format($m['total_payroll'] + $m['total_expenses'], 2, '.', ''),
                number_format($m['net'],            2, '.', ''),
            ];
        }

        $rows[] = [];
        $totalIncome   = array_sum(array_column($months, 'total_income'));
        $totalPayroll  = array_sum(array_column($months, 'total_payroll'));
        $totalExpenses = array_sum(array_column($months, 'total_expenses'));
        $totalNet      = array_sum(array_column($months, 'net'));

        $rows[] = [
            'TOTAL',
            number_format($totalIncome,   2, '.', ''),
            number_format($totalPayroll,  2, '.', ''),
            number_format($totalExpenses, 2, '.', ''),
            number_format($totalPayroll + $totalExpenses, 2, '.', ''),
            number_format($totalNet,      2, '.', ''),
        ];

        // Append journal entries per month
        $rows[] = [];
        $rows[] = ['JOURNAL D\'ÉCRITURE ' . $year];

        for ($m = 1; $m <= 12; $m++) {
            $journal = $this->buildJournalEntries($schoolId, $year, $m);
            if (empty($journal)) continue;

            $rows[] = [];
            $rows[] = [$monthNames[$m - 1] . ' ' . $year];
            $rows[] = ['Date', 'N° Compte', 'Libellé', 'Débit', 'Crédit'];

            foreach ($journal as $j) {
                $rows[] = [
                    $j['date'],
                    $j['account_code'],
                    $j['label'],
                    $j['debit']  > 0 ? number_format($j['debit'],  2, '.', '') : '',
                    $j['credit'] > 0 ? number_format($j['credit'], 2, '.', '') : '',
                ];
            }
        }

        $csv = implode("\n", array_map(
            fn ($row) => implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $row)),
            $rows
        ));

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"comptabilite_{$year}.csv\"",
        ]);
    }

    // ── Export monthly CSV ────────────────────────────────────────────────────

    public function exportMonthly(Request $r)
    {
        $r->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $month    = (int) $r->month;
        $year     = (int) $r->year;
        $schoolId = $this->schoolId();

        $data    = $this->buildMonthData($schoolId, $year, $month);
        $journal = $this->buildJournalEntries($schoolId, $year, $month);

        $monthNames = ['Janvier','Février','Mars','Avril','Mai','Juin',
                       'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        $label = ($monthNames[$month - 1] ?? $month) . ' ' . $year;

        $rows = [];
        $rows[] = ['SITUATION COMPTABLE - ' . strtoupper($label)];
        $rows[] = [];
        $rows[] = ['RECETTES'];
        $rows[] = ['Total recettes', number_format($data['total_income'], 2, '.', '')];
        $rows[] = [];
        $rows[] = ['CHARGES'];
        $rows[] = ['Masse salariale', number_format($data['total_payroll'],  2, '.', '')];
        $rows[] = ['Dépenses générales', number_format($data['total_expenses'], 2, '.', '')];
        $rows[] = ['Total charges', number_format($data['total_payroll'] + $data['total_expenses'], 2, '.', '')];
        $rows[] = [];
        $rows[] = ['RÉSULTAT NET', number_format($data['net'], 2, '.', '')];
        $rows[] = [];
        $rows[] = ['Détail de la masse salariale par catégorie'];
        $rows[] = ['Catégorie', 'Montant'];
        foreach ($data['payroll_breakdown'] as $pb) {
            $rows[] = [$pb['type_label'], number_format($pb['total'], 2, '.', '')];
        }
        $rows[] = [];
        $rows[] = ['Détail des dépenses générales par catégorie'];
        $rows[] = ['Catégorie', 'Montant'];
        foreach ($data['expense_breakdown'] as $eb) {
            $rows[] = [$eb['category_label'], number_format($eb['total'], 2, '.', '')];
        }

        if (! empty($journal)) {
            $rows[] = [];
            $rows[] = ['JOURNAL D\'ÉCRITURE'];
            $rows[] = ['Date', 'N° Compte', 'Libellé', 'Débit', 'Crédit'];
            foreach ($journal as $j) {
                $rows[] = [
                    $j['date'],
                    $j['account_code'],
                    $j['label'],
                    $j['debit']  > 0 ? number_format($j['debit'],  2, '.', '') : '',
                    $j['credit'] > 0 ? number_format($j['credit'], 2, '.', '') : '',
                ];
            }
        }

        $csv = implode("\n", array_map(
            fn ($row) => implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $row)),
            $rows
        ));

        $filename = 'comptabilite_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildMonthData(int $schoolId, int $year, int $month): array
    {
        $monthNames = ['Janvier','Février','Mars','Avril','Mai','Juin',
                       'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

        // Income: payments collected this month
        $income = Payment::where('school_id', $schoolId)
            ->where('year', $year)
            ->where('month', $month)
            ->whereIn('status', ['paid', 'partial'])
            ->sum('amount');

        // Payroll entries for this month
        $payrollEntries = PayrollEntry::where('school_id', $schoolId)
            ->where('year', $year)
            ->where('month', $month)
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalPayroll = $payrollEntries->sum(fn ($e) => (float) $e->total_amount);

        $payrollBreakdown = $payrollEntries
            ->groupBy('type')
            ->map(fn ($grp, $type) => [
                'type'       => $type,
                'type_label' => ['salary' => 'Salaires', 'advance' => 'Avances', 'bonus' => 'Primes'][$type] ?? $type,
                'total'      => $grp->sum(fn ($e) => (float) $e->total_amount),
                'count'      => $grp->count(),
            ])
            ->values()
            ->toArray();

        // General staff expenses for this month (non-salary categories)
        $expenses = StaffExpense::where('school_id', $schoolId)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->whereNotIn('category', ['salary', 'salary_advance'])
            ->whereNotIn('status', ['pending'])
            ->get();

        $totalExpenses = $expenses->sum(fn ($e) => (float) $e->amount);

        $catLabels = [
            'transport'   => 'Transport',
            'supplies'    => 'Fournitures',
            'equipment'   => 'Équipement',
            'maintenance' => 'Maintenance',
            'other'       => 'Autres',
        ];

        $expenseBreakdown = $expenses
            ->groupBy('category')
            ->map(fn ($grp, $cat) => [
                'category'       => $cat,
                'category_label' => $catLabels[$cat] ?? $cat,
                'total'          => $grp->sum(fn ($e) => (float) $e->amount),
                'count'          => $grp->count(),
            ])
            ->values()
            ->toArray();

        return [
            'month'              => $month,
            'year'               => $year,
            'month_label'        => ($monthNames[$month - 1] ?? $month) . ' ' . $year,
            'total_income'       => (float) $income,
            'total_payroll'      => $totalPayroll,
            'total_expenses'     => $totalExpenses,
            'net'                => (float) $income - $totalPayroll - $totalExpenses,
            'payroll_breakdown'  => $payrollBreakdown,
            'expense_breakdown'  => $expenseBreakdown,
        ];
    }

    /**
     * Build journal d'écriture entries for a month.
     * Uses a simplified Plan Comptable Marocain.
     *
     * Account codes used:
     *   7071 — Produits scolaires (revenus)
     *   6171 — Rémunérations du personnel
     *   6174 — Avances sur rémunérations
     *   6179 — Primes et gratifications
     *   6132 — Transport
     *   6141 — Fournitures de bureau
     *   6143 — Matériel et équipement
     *   6144 — Maintenance
     *   6199 — Autres charges
     *   5141 — Banque / Caisse
     */
    private function buildJournalEntries(int $schoolId, int $year, int $month): array
    {
        $entries = [];
        $lastDay = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year));
        $dateLabel = $lastDay;

        // Income entries
        $income = Payment::where('school_id', $schoolId)
            ->where('year', $year)
            ->where('month', $month)
            ->whereIn('status', ['paid', 'partial'])
            ->sum('amount');

        if ($income > 0) {
            // Debit Banque (5141)
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '5141',
                'label'        => 'Banque - Encaissements frais de scolarité',
                'debit'        => (float) $income,
                'credit'       => 0.0,
            ];
            // Credit Produits scolaires (7071)
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '7071',
                'label'        => 'Produits scolaires',
                'debit'        => 0.0,
                'credit'       => (float) $income,
            ];
        }

        // Payroll entries
        $payroll = PayrollEntry::where('school_id', $schoolId)
            ->where('year', $year)
            ->where('month', $month)
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $salaryTotal  = $payroll->where('type', 'salary')->sum(fn ($e) => (float) $e->total_amount);
        $advanceTotal = $payroll->where('type', 'advance')->sum(fn ($e) => (float) $e->total_amount);
        $bonusTotal   = $payroll->where('type', 'bonus')->sum(fn ($e) => (float) $e->total_amount);

        if ($salaryTotal > 0) {
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '6171',
                'label'        => 'Rémunérations du personnel',
                'debit'        => $salaryTotal,
                'credit'       => 0.0,
            ];
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '5141',
                'label'        => 'Banque - Paiement salaires',
                'debit'        => 0.0,
                'credit'       => $salaryTotal,
            ];
        }

        if ($advanceTotal > 0) {
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '6174',
                'label'        => 'Avances sur rémunérations',
                'debit'        => $advanceTotal,
                'credit'       => 0.0,
            ];
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '5141',
                'label'        => 'Banque - Avances sur salaires',
                'debit'        => 0.0,
                'credit'       => $advanceTotal,
            ];
        }

        if ($bonusTotal > 0) {
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '6179',
                'label'        => 'Primes et gratifications',
                'debit'        => $bonusTotal,
                'credit'       => 0.0,
            ];
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '5141',
                'label'        => 'Banque - Paiement primes',
                'debit'        => 0.0,
                'credit'       => $bonusTotal,
            ];
        }

        // General expenses
        $expenseAccountMap = [
            'transport'   => ['6132', 'Transport'],
            'supplies'    => ['6141', 'Fournitures de bureau'],
            'equipment'   => ['6143', 'Matériel et équipement'],
            'maintenance' => ['6144', 'Maintenance'],
            'other'       => ['6199', 'Autres charges'],
        ];

        $expenses = StaffExpense::where('school_id', $schoolId)
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->whereNotIn('category', ['salary', 'salary_advance'])
            ->whereNotIn('status', ['pending'])
            ->get();

        $byCategory = $expenses->groupBy('category');
        foreach ($byCategory as $cat => $grp) {
            $amount = $grp->sum(fn ($e) => (float) $e->amount);
            if ($amount <= 0) continue;

            [$code, $catLabel] = $expenseAccountMap[$cat] ?? ['6199', 'Autres charges'];
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => $code,
                'label'        => $catLabel,
                'debit'        => $amount,
                'credit'       => 0.0,
            ];
            $entries[] = [
                'date'         => $dateLabel,
                'account_code' => '5141',
                'label'        => 'Banque - ' . $catLabel,
                'debit'        => 0.0,
                'credit'       => $amount,
            ];
        }

        return $entries;
    }
}
