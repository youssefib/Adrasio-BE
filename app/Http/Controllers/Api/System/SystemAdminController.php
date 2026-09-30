<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\UpdateSchoolRequest;
use App\Http\Requests\System\UpdateSubscriptionRequest;
use App\Models\School;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints exclusively for the system_admin role.
 * No school_id scoping — sees everything.
 */
class SystemAdminController extends Controller
{
    /** GET /api/v1/system/dashboard */
    public function dashboard(): JsonResponse
    {
        $now = now();

        $schoolsByStatus = School::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $schoolsByTier = School::selectRaw('subscription_tier, count(*) as total')
            ->groupBy('subscription_tier')
            ->pluck('total', 'subscription_tier');

        $usersByRole = \DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('roles.name as role, count(*) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'role');

        $activeUsers24h = User::where('last_login_at', '>=', $now->copy()->subHours(24))->count();
        $activeUsers7d  = User::where('last_login_at', '>=', $now->copy()->subDays(7))->count();

        // Schools still on 'trial' whose trial end date has passed.
        $trialsEndedQuery = School::where('status', 'trial')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', $now);

        $trialsEnded = (clone $trialsEndedQuery)
            ->orderBy('trial_ends_at')
            ->limit(100)
            ->get(['id', 'name', 'trial_ends_at'])
            ->map(fn (School $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'trial_ends_at' => optional($s->trial_ends_at)->toDateString(),
            ]);

        return response()->json([
            'schools_by_status'  => $schoolsByStatus,
            'schools_by_tier'    => $schoolsByTier,
            'total_schools'      => School::count(),
            'users_by_role'      => $usersByRole,
            'total_users'        => User::count(),
            'active_users_24h'   => $activeUsers24h,
            'active_users_7d'    => $activeUsers7d,
            'trials_ended'       => $trialsEnded,
            'trials_ended_count' => (clone $trialsEndedQuery)->count(),
        ]);
    }

    /** GET /api/v1/system/stats (legacy — kept for backward compat) */
    public function stats(): JsonResponse
    {
        return response()->json([
            'schools'         => School::count(),
            'active_schools'  => School::where('status', 'active')->count(),
            'trial_schools'   => School::where('status', 'trial')->count(),
            'suspended'       => School::where('status', 'suspended')->count(),
            'total_users'     => User::count(),
            'total_students'  => User::role('student')->count(),
            'total_teachers'  => User::role('teacher')->count(),
        ]);
    }

    public function schools(Request $request): JsonResponse
    {
        $schools = School::with('plan')
            ->withCount(['users'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($schools);
    }

    public function showSchool(School $school): JsonResponse
    {
        return response()->json($school->load('plan'));
    }

    public function updateSchool(UpdateSchoolRequest $request, School $school): JsonResponse
    {
        $school->update($request->validated());

        ActivityLogger::log('school.updated', "School '{$school->name}' updated by system admin.", [
            'changes' => $request->validated(),
        ]);

        return response()->json($school->fresh('plan'));
    }

    /** PATCH /api/v1/system/schools/{school}/subscription */
    public function updateSubscription(UpdateSubscriptionRequest $request, School $school): JsonResponse
    {
        $school->update($request->validated());

        ActivityLogger::log('school.subscription_changed', "Subscription changed for '{$school->name}'.", [
            'tier' => $request->subscription_tier,
        ]);

        return response()->json($school->fresh('plan'));
    }

    public function destroySchool(School $school): JsonResponse
    {
        $school->delete();

        return response()->json(['message' => 'School soft-deleted.']);
    }

    /**
     * POST /api/v1/system/schools/{school}/reset
     *
     * Permanently deletes selected categories of a school's data. The school
     * record and its OWNER account are always kept. Requires the admin to type
     * the exact school name as confirmation.
     *
     * Deletes are raw (bypass soft-deletes) and scoped by school_id; DB
     * foreign-key cascades handle child rows. All relevant FKs are cascade or
     * nullOnDelete (no restrict), so order is safe.
     */
    public function resetSchool(Request $request, School $school): JsonResponse
    {
        $data = $request->validate([
            'confirm'          => 'required|string',
            'students'         => 'sometimes|boolean',
            'staff'            => 'sometimes|boolean',
            'finance'          => 'sometimes|boolean',
            'expenses_payroll' => 'sometimes|boolean',
            'classes'          => 'sometimes|boolean',
            'attendance'       => 'sometimes|boolean',
            'files'            => 'sometimes|boolean',
        ]);

        abort_if(
            trim($data['confirm']) !== $school->name,
            422,
            'The confirmation name does not match the school name.'
        );

        $id      = $school->id;
        $deleted = [];

        DB::transaction(function () use ($id, $data, &$deleted) {
            if ($data['finance'] ?? false) {
                $deleted['payments']           = DB::table('payments')->where('school_id', $id)->delete();
                $deleted['course_payments']    = DB::table('course_payments')->where('school_id', $id)->delete();
                $deleted['pack_payments']      = DB::table('class_group_payments')->where('school_id', $id)->delete();
                $deleted['additional_charges'] = DB::table('additional_charges')->where('school_id', $id)->delete();
                $deleted['refunds']            = DB::table('refunds')->where('school_id', $id)->delete();
            }
            if ($data['expenses_payroll'] ?? false) {
                $deleted['staff_expenses']  = DB::table('staff_expenses')->where('school_id', $id)->delete();
                $deleted['payroll_entries'] = DB::table('payroll_entries')->where('school_id', $id)->delete();
            }
            if ($data['attendance'] ?? false) {
                $deleted['attendances'] = DB::table('attendances')->where('school_id', $id)->delete();
            }
            if ($data['classes'] ?? false) {
                // Enrollments first (cascade their payments/statuses), then classes/courses/packs/timetable.
                $deleted['course_enrollments']      = DB::table('course_enrollments')->where('school_id', $id)->delete();
                $deleted['class_group_enrollments'] = DB::table('class_group_enrollments')->where('school_id', $id)->delete();
                $deleted['timetable_slots']         = DB::table('timetable_slots')->where('school_id', $id)->delete();
                $deleted['course_classes']          = DB::table('course_classes')->where('school_id', $id)->delete();
                $deleted['class_groups']            = DB::table('class_groups')->where('school_id', $id)->delete();
                $deleted['course_levels']           = DB::table('course_levels')->where('school_id', $id)->delete();
                $deleted['courses']                 = DB::table('courses')->where('school_id', $id)->delete();
                $deleted['classrooms']              = DB::table('classrooms')->where('school_id', $id)->delete();
            }
            if ($data['files'] ?? false) {
                $deleted['files'] = DB::table('files')->where('school_id', $id)->delete();
                Storage::disk('local')->deleteDirectory("schools/{$id}/files");
            }
            if ($data['students'] ?? false) {
                // Deleting student users cascades profiles, enrollments, attendance, payments, class links.
                $deleted['students'] = DB::table('users')->where('school_id', $id)->where('role', 'student')->delete();
            }
            if ($data['staff'] ?? false) {
                // Non-owner staff. Cascades their payroll, timetable, commissions and uploaded files.
                $deleted['staff'] = DB::table('users')->where('school_id', $id)
                    ->whereIn('role', ['teacher', 'admin'])->delete();
            }
        });

        $categories = collect($data)->except('confirm')->filter()->keys()->all();

        ActivityLogger::log(
            'school.reset',
            "School '{$school->name}' data reset by system admin.",
            ['categories' => $categories, 'deleted' => $deleted],
            $school->id,
        );

        return response()->json(['message' => 'School reset complete.', 'deleted' => $deleted]);
    }

    // ── Subscription Plans ────────────────────────────────────────────────────

    public function plans(): JsonResponse
    {
        return response()->json(SubscriptionPlan::all());
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'slug'             => 'required|string|unique:subscription_plans,slug',
            'description'      => 'nullable|string',
            'max_students'     => 'nullable|integer|min:1',
            'max_teachers'     => 'nullable|integer|min:1',
            'max_classes'      => 'nullable|integer|min:1',
            'storage_limit_mb' => 'sometimes|integer|min:1',
            'price_monthly'    => 'required|numeric|min:0',
            'price_yearly'     => 'required|numeric|min:0',
            'is_active'        => 'sometimes|boolean',
        ]);

        return response()->json(SubscriptionPlan::create($data), 201);
    }

    public function updatePlan(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'sometimes|string|max:100',
            'description'           => 'nullable|string',
            'max_students'          => 'nullable|integer|min:1',
            'max_teachers'          => 'nullable|integer|min:1',
            'max_classes'           => 'nullable|integer|min:1',
            'storage_limit_mb'      => 'sometimes|integer|min:0',
            'price_monthly'         => 'sometimes|numeric|min:0',
            'price_yearly'          => 'sometimes|numeric|min:0',
            'price_3months'         => 'sometimes|numeric|min:0',
            'price_6months'         => 'sometimes|numeric|min:0',
            'allows_both_types'     => 'sometimes|boolean',
            'allows_file_upload'    => 'sometimes|boolean',
            'allows_teacher_portal' => 'sometimes|boolean',
            'is_active'             => 'sometimes|boolean',
        ]);

        $subscriptionPlan->update($data);

        return response()->json($subscriptionPlan->fresh());
    }

    // ── System Settings ───────────────────────────────────────────────────────

    public function getSettings(): JsonResponse
    {
        return response()->json(\App\Models\SystemSetting::all());
    }

    public function updateSetting(Request $request, string $key): JsonResponse
    {
        $setting = \App\Models\SystemSetting::where('key', $key)->firstOrFail();

        $request->validate(['value' => 'required']);

        // Type-check the value
        $value = $request->input('value');
        if ($setting->type === 'integer' && ! is_numeric($value)) {
            return response()->json(['errors' => ['value' => ['Must be a number.']]], 422);
        }
        if ($setting->type === 'boolean' && ! in_array($value, ['true', 'false', '1', '0', true, false], true)) {
            return response()->json(['errors' => ['value' => ['Must be a boolean.']]], 422);
        }

        $setting->update(['value' => (string) $value]);

        return response()->json($setting->fresh());
    }
}
