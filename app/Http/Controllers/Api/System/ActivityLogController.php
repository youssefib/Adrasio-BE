<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Traits\ScopedToSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ScopedToSchool;

    /**
     * GET /api/v1/system/activity-logs  (system_admin only — sees all)
     */
    public function systemLogs(Request $request): JsonResponse
    {
        $logs = ActivityLog::with(['user', 'school'])
            ->when($request->school_id, fn ($q) => $q->where('school_id', $request->school_id))
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($request->from, fn ($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('created_at', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * GET /api/v1/activity-logs  (school_owner / admin — scoped to their school)
     */
    public function schoolLogs(Request $request): JsonResponse
    {
        $school = $this->currentSchool($request);

        $logs = ActivityLog::with('user')
            ->where('school_id', $school->id)
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($request->from, fn ($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('created_at', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }
}
