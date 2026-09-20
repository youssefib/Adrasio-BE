<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * File-based activity log.
 *
 * Logs are stored as newline-delimited JSON (one JSON object per line) in
 * daily files, laid out one folder per school → one folder per month → one
 * file per day:
 *
 *   storage/app/school-logs/{schoolId}/{YYYY-MM}/{YYYY-MM-DD}.log
 *
 * Actions with no resolvable school land under the "_system" folder.
 * Only the system-admin side reads these files (see ActivityLogController).
 */
class ActivityLogger
{
    /**
     * Append an activity event to today's log file for the school.
     *
     * @param  string  $action      e.g. "user.login", "payment.marked"
     * @param  string  $description Human-readable sentence
     * @param  array   $metadata    Extra context stored inline
     */
    public static function log(
        string $action,
        string $description,
        array $metadata = [],
        ?int $schoolId = null,
        ?int $userId = null,
    ): void {
        /** @var Request $request */
        $request = app(Request::class);

        $authUser = Auth::user();
        $schoolId = $schoolId ?? $authUser?->school_id;
        $userId   = $userId   ?? $authUser?->id;

        // Resolve the actor's name without an extra query when possible.
        // Logging must never break the underlying request, so swallow lookups.
        $userName = null;
        try {
            if ($authUser && $authUser->id === $userId) {
                $userName = $authUser->name;
            } elseif ($userId) {
                $userName = optional(User::find($userId))->name;
            }
        } catch (\Throwable $e) {
            $userName = null;
        }

        $now = now();

        $entry = [
            'timestamp'   => $now->toIso8601String(),
            'action'      => $action,
            'description' => $description,
            'user_id'     => $userId,
            'user_name'   => $userName,
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'metadata'    => empty($metadata) ? null : $metadata,
        ];

        $dir = static::schoolDir($schoolId) . '/' . $now->format('Y-m');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . $now->format('Y-m-d') . '.log';
        @file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Available months + days that have logs for a school, newest first.
     *
     * @return array{months: array<int, array{month: string, days: array<int, string>}>}
     */
    public static function availability(int $schoolId): array
    {
        $base = static::schoolDir($schoolId);
        $months = [];

        if (is_dir($base)) {
            foreach (static::subDirs($base) as $month) {
                if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                    continue;
                }
                $days = [];
                foreach (glob($base . '/' . $month . '/*.log') ?: [] as $path) {
                    $day = basename($path, '.log');
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                        $days[] = $day;
                    }
                }
                if ($days) {
                    rsort($days);
                    $months[] = ['month' => $month, 'days' => $days];
                }
            }
        }

        usort($months, fn ($a, $b) => strcmp($b['month'], $a['month']));

        return ['months' => $months];
    }

    /**
     * Parsed entries for a single day, newest first. Optional action filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function readDay(int $schoolId, string $date, ?string $action = null): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        $month = substr($date, 0, 7);
        $file  = static::schoolDir($schoolId) . '/' . $month . '/' . $date . '.log';

        if (! is_file($file)) {
            return [];
        }

        $entries = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }
            if ($action && ($row['action'] ?? null) !== $action) {
                continue;
            }
            $entries[] = $row;
        }

        return array_reverse($entries);
    }

    protected static function baseDir(): string
    {
        return storage_path('app/school-logs');
    }

    protected static function schoolDir(?int $schoolId): string
    {
        return static::baseDir() . '/' . ($schoolId ?: '_system');
    }

    /** @return array<int, string> immediate subdirectory names */
    protected static function subDirs(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($dir . '/' . $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
