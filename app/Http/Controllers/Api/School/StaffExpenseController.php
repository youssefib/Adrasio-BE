<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\StaffExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffExpenseController extends Controller
{
    private function schoolId(): int
    {
        return Auth::user()->school_id;
    }

    public function index(Request $r): JsonResponse
    {
        $schoolId = $this->schoolId();

        $query = StaffExpense::where('school_id', $schoolId)
            ->with('user:id,name,role')
            ->orderByDesc('expense_date');

        if ($r->filled('user_id')) {
            $query->where('user_id', $r->user_id);
        }
        if ($r->filled('status')) {
            $query->where('status', $r->status);
        }
        if ($r->filled('category')) {
            $query->where('category', $r->category);
        }
        if ($r->filled('date_from')) {
            $query->where('expense_date', '>=', $r->date_from);
        }
        if ($r->filled('date_to')) {
            $query->where('expense_date', '<=', $r->date_to);
        }

        return response()->json($query->paginate(30));
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'user_id'      => 'nullable|exists:users,id',
            'category'     => 'required|in:salary,salary_advance,transport,supplies,equipment,maintenance,other',
            'description'  => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'status'       => 'sometimes|in:pending,approved,paid',
            'notes'        => 'nullable|string',
        ]);

        $expense = StaffExpense::create(array_merge($data, ['school_id' => $this->schoolId()]));
        $expense->load('user:id,name,role');

        return response()->json($expense, 201);
    }

    public function update(Request $r, StaffExpense $expense): JsonResponse
    {
        abort_if($expense->school_id !== $this->schoolId(), 403);

        $data = $r->validate([
            'user_id'      => 'nullable|exists:users,id',
            'category'     => 'required|in:salary,salary_advance,transport,supplies,equipment,maintenance,other',
            'description'  => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'status'       => 'sometimes|in:pending,approved,paid',
            'notes'        => 'nullable|string',
        ]);

        $expense->update($data);
        $expense->load('user:id,name,role');

        return response()->json($expense);
    }

    public function destroy(StaffExpense $expense): JsonResponse
    {
        abort_if($expense->school_id !== $this->schoolId(), 403);
        $expense->delete();

        return response()->json(null, 204);
    }

    public function report(Request $r): JsonResponse
    {
        $r->validate([
            'period'  => 'required|in:monthly,quarterly,yearly',
            'year'    => 'required|integer',
            'month'   => 'required_if:period,monthly|nullable|integer|min:1|max:12',
            'quarter' => 'required_if:period,quarterly|nullable|integer|min:1|max:4',
        ]);

        $schoolId = $this->schoolId();
        $query    = StaffExpense::where('school_id', $schoolId)->with('user:id,name');

        $period  = $r->period;
        $year    = (int) $r->year;
        $label   = '';

        if ($period === 'monthly') {
            $month = (int) $r->month;
            $query->whereYear('expense_date', $year)->whereMonth('expense_date', $month);
            $months = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
            $label = ($months[$month - 1] ?? $month) . ' ' . $year;
        } elseif ($period === 'quarterly') {
            $quarter   = (int) $r->quarter;
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth   = $startMonth + 2;
            $query->whereYear('expense_date', $year)
                  ->whereMonth('expense_date', '>=', $startMonth)
                  ->whereMonth('expense_date', '<=', $endMonth);
            $label = 'T' . $quarter . ' ' . $year;
        } else {
            $query->whereYear('expense_date', $year);
            $label = (string) $year;
        }

        $expenses = $query->get();

        $byCategory = $expenses->groupBy('category')->map(function ($group, $cat) {
            return ['category' => $cat, 'total' => $group->sum('amount'), 'count' => $group->count()];
        })->values();

        $byUser = $expenses->groupBy('user_id')->map(function ($group) {
            $first = $group->first();
            return [
                'user_id'   => $first->user_id,
                'user_name' => $first->user?->name ?? '—',
                'total'     => $group->sum('amount'),
                'count'     => $group->count(),
            ];
        })->values();

        return response()->json([
            'period_label'      => $label,
            'total_amount'      => $expenses->sum('amount'),
            'transaction_count' => $expenses->count(),
            'by_category'       => $byCategory,
            'by_user'           => $byUser,
        ]);
    }
}
