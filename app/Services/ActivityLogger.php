<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    /**
     * Log an activity event.
     *
     * @param  string  $action      e.g. "user.login", "payment.marked_paid"
     * @param  string  $description Human-readable sentence
     * @param  array   $metadata    Extra context stored as JSON
     */
    public static function log(
        string $action,
        string $description,
        array $metadata = [],
        ?int $schoolId = null,
        ?int $userId = null,
    ): ActivityLog {
        /** @var Request $request */
        $request = app(Request::class);

        return ActivityLog::create([
            'school_id'   => $schoolId  ?? Auth::user()?->school_id,
            'user_id'     => $userId    ?? Auth::id(),
            'action'      => $action,
            'description' => $description,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'metadata'    => empty($metadata) ? null : $metadata,
        ]);
    }
}
