<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\UpdateSchoolRequest;
use App\Http\Requests\System\UpdateSubscriptionRequest;
use App\Models\ActivityLog;
use App\Models\School;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $activeUsers24h = ActivityLog::where('action', 'user.login')
            ->where('created_at', '>=', $now->copy()->subHours(24))
            ->distinct('user_id')
            ->count('user_id');

        $activeUsers7d = ActivityLog::where('action', 'user.login')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'schools_by_status' => $schoolsByStatus,
            'schools_by_tier'   => $schoolsByTier,
            'total_schools'     => School::count(),
            'users_by_role'     => $usersByRole,
            'total_users'       => User::count(),
            'active_users_24h'  => $activeUsers24h,
            'active_users_7d'   => $activeUsers7d,
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
