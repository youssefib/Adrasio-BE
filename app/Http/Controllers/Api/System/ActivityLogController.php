<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System-admin only. Reads the file-based activity logs written by
 * App\Services\ActivityLogger. The admin picks a school, then a month + day.
 */
class ActivityLogController extends Controller
{
    /**
     * GET /api/v1/system/school-logs/availability?school_id=
     * Returns the months (and their days) that have logs for the school.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
        ]);

        return response()->json(ActivityLogger::availability((int) $data['school_id']));
    }

    /**
     * GET /api/v1/system/school-logs/entries?school_id=&date=YYYY-MM-DD&action=
     * Returns the parsed log entries for a single day, newest first.
     */
    public function entries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
            'date'      => 'required|date_format:Y-m-d',
            'action'    => 'sometimes|nullable|string|max:100',
        ]);

        $entries = ActivityLogger::readDay(
            (int) $data['school_id'],
            $data['date'],
            $data['action'] ?? null,
        );

        return response()->json([
            'date'    => $data['date'],
            'count'   => count($entries),
            'entries' => $entries,
        ]);
    }
}
