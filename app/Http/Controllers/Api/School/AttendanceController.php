<?php

namespace App\Http\Controllers\Api\School;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\CourseLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{
    private function schoolId(): int
    {
        return Auth::user()->school_id;
    }

    /**
     * GET /school/attendance?class_type=classroom&class_id=1&date=2026-06-08
     */
    public function index(Request $r): JsonResponse
    {
        $r->validate([
            'class_type' => 'required|in:classroom,course_class',
            'class_id'   => 'required|integer',
            'date'       => 'required|date',
        ]);

        $schoolId  = $this->schoolId();
        $classType = $r->class_type;
        $classId   = (int) $r->class_id;
        $date      = $r->date;

        // Get all students
        $students = [];
        if ($classType === 'classroom') {
            $classroom = Classroom::where('school_id', $schoolId)->findOrFail($classId);
            foreach ($classroom->students as $sp) {
                $students[] = ['id' => $sp->id, 'name' => $sp->user->name ?? 'N/A'];
            }
        } else {
            $enrollments = CourseEnrollment::with('studentProfile.user')
                ->where('school_id', $schoolId)
                ->where('course_class_id', $classId)
                ->where('status', 'active')
                ->get();
            foreach ($enrollments as $e) {
                if ($e->studentProfile) {
                    $students[] = [
                        'id'   => $e->studentProfile->id,
                        'name' => $e->studentProfile->user->name ?? 'N/A',
                    ];
                }
            }
        }

        // Get existing attendance for this class/date
        $existingQuery = Attendance::where('school_id', $schoolId)
            ->where('class_type', $classType)
            ->where('date', $date);

        if ($classType === 'classroom') {
            $existingQuery->where('classroom_id', $classId);
        } else {
            $existingQuery->where('course_class_id', $classId);
        }

        $existing = $existingQuery->get()->keyBy('student_profile_id');

        $records = array_map(function ($s) use ($existing) {
            $att = $existing[$s['id']] ?? null;
            return [
                'student_profile_id' => $s['id'],
                'student_name'       => $s['name'],
                'status'             => $att?->status ?? null,
                'notes'              => $att?->notes ?? null,
                'attendance_id'      => $att?->id ?? null,
            ];
        }, $students);

        return response()->json($records);
    }

    /**
     * POST /school/attendance/bulk
     */
    public function bulk(Request $r): JsonResponse
    {
        $r->validate([
            'class_type'               => 'required|in:classroom,course_class',
            'class_id'                 => 'required|integer',
            'date'                     => 'required|date',
            'records'                  => 'required|array',
            'records.*.student_profile_id' => 'required|integer',
            'records.*.status'         => 'required|in:present,absent,late,excused',
            'records.*.notes'          => 'nullable|string',
        ]);

        $schoolId  = $this->schoolId();
        $classType = $r->class_type;
        $classId   = (int) $r->class_id;
        $date      = $r->date;

        $saved = [];
        foreach ($r->records as $rec) {
            $base = [
                'school_id'          => $schoolId,
                'class_type'         => $classType,
                'student_profile_id' => $rec['student_profile_id'],
                'date'               => $date,
            ];
            if ($classType === 'classroom') {
                $base['classroom_id']    = $classId;
                $base['course_class_id'] = null;
            } else {
                $base['course_class_id'] = $classId;
                $base['classroom_id']    = null;
            }

            $attendance = Attendance::updateOrCreate(
                array_merge($base, ['student_profile_id' => $rec['student_profile_id']]),
                [
                    'status' => $rec['status'],
                    'notes'  => $rec['notes'] ?? null,
                ]
            );
            $saved[] = $attendance;
        }

        return response()->json($saved);
    }

    /**
     * GET /school/attendance/report/monthly?class_type=classroom&class_id=1&year=2026&month=6
     */
    public function reportMonthly(Request $r): JsonResponse
    {
        $r->validate([
            'class_type' => 'required|in:classroom,course_class',
            'class_id'   => 'required|integer',
            'year'       => 'required|integer',
            'month'      => 'required|integer|min:1|max:12',
        ]);

        $schoolId  = $this->schoolId();
        $classType = $r->class_type;
        $classId   = (int) $r->class_id;
        $year      = (int) $r->year;
        $month     = (int) $r->month;

        // Get class name and students
        $className = '';
        $students  = [];
        if ($classType === 'classroom') {
            $classroom = Classroom::where('school_id', $schoolId)->findOrFail($classId);
            $className = $classroom->name;
            foreach ($classroom->students as $sp) {
                $students[] = ['id' => $sp->id, 'name' => $sp->user->name ?? 'N/A'];
            }
        } else {
            $cc = CourseClass::where('school_id', $schoolId)->findOrFail($classId);
            $className   = $cc->name;
            $enrollments = CourseEnrollment::with('studentProfile.user')
                ->where('school_id', $schoolId)
                ->where('course_class_id', $classId)
                ->where('status', 'active')
                ->get();
            foreach ($enrollments as $e) {
                if ($e->studentProfile) {
                    $students[] = [
                        'id'   => $e->studentProfile->id,
                        'name' => $e->studentProfile->user->name ?? 'N/A',
                    ];
                }
            }
        }

        $query = Attendance::where('school_id', $schoolId)
            ->where('class_type', $classType)
            ->whereYear('date', $year)
            ->whereMonth('date', $month);

        if ($classType === 'classroom') {
            $query->where('classroom_id', $classId);
        } else {
            $query->where('course_class_id', $classId);
        }

        $attendances = $query->get()->groupBy('student_profile_id');

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $days        = range(1, $daysInMonth);

        $studentData = array_map(function ($s) use ($attendances, $days) {
            $recs     = $attendances[$s['id']] ?? collect();
            $statuses = [];
            foreach ($days as $d) {
                $att = $recs->first(fn ($a) => (int) $a->date->day === $d);
                $statuses[$d] = $att?->status ?? null;
            }
            $totals = [
                'present' => $recs->where('status', 'present')->count(),
                'absent'  => $recs->where('status', 'absent')->count(),
                'late'    => $recs->where('status', 'late')->count(),
                'excused' => $recs->where('status', 'excused')->count(),
            ];
            return ['id' => $s['id'], 'name' => $s['name'], 'statuses' => $statuses, 'totals' => $totals];
        }, $students);

        return response()->json([
            'class_name' => $className,
            'year'       => $year,
            'month'      => $month,
            'days'       => $days,
            'students'   => $studentData,
        ]);
    }

    /**
     * GET /school/attendance/report/level?course_level_id=1&year=2026&month=6
     */
    public function reportLevel(Request $r): JsonResponse
    {
        $r->validate([
            'course_level_id' => 'required|integer',
            'year'            => 'required|integer',
            'month'           => 'required|integer|min:1|max:12',
        ]);

        $schoolId = $this->schoolId();
        $levelId  = (int) $r->course_level_id;
        $year     = (int) $r->year;
        $month    = (int) $r->month;

        $level = CourseLevel::where('school_id', $schoolId)->findOrFail($levelId);
        $classes = CourseClass::where('school_id', $schoolId)
            ->where('course_level_id', $levelId)
            ->with('teacher')
            ->get();

        $classesData = $classes->map(function ($cc) use ($schoolId, $year, $month) {
            $studentsCount = CourseEnrollment::where('school_id', $schoolId)
                ->where('course_class_id', $cc->id)
                ->where('status', 'active')
                ->count();

            $attendances = Attendance::where('school_id', $schoolId)
                ->where('class_type', 'course_class')
                ->where('course_class_id', $cc->id)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->get();

            $daysTracked  = $attendances->pluck('date')->unique()->count();
            $totalRecords = $attendances->count();
            $presentCount = $attendances->where('status', 'present')->count();
            $presentPct   = $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 1) : 0;

            return [
                'class_id'      => $cc->id,
                'class_name'    => $cc->name,
                'teacher_name'  => $cc->teacher?->name,
                'students_count' => $studentsCount,
                'days_tracked'  => $daysTracked,
                'present_pct'   => $presentPct,
            ];
        });

        return response()->json([
            'level_name' => $level->name,
            'classes'    => $classesData,
        ]);
    }
}
