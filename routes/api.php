<?php

use App\Http\Controllers\Api\Admin\BankingInfoController;
use App\Http\Controllers\Api\Admin\SchoolController;
use App\Http\Controllers\Api\Admin\SubscriptionRequestController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\School\SubscriptionController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\School\AdditionalChargeController;
use App\Http\Controllers\Api\School\AttendanceController;
use App\Http\Controllers\Api\School\ClassroomController;
use App\Http\Controllers\Api\School\CourseClassController;
use App\Http\Controllers\Api\School\CourseController;
use App\Http\Controllers\Api\School\CourseEnrollmentController;
use App\Http\Controllers\Api\School\CourseLevelController;
use App\Http\Controllers\Api\School\DashboardController;
use App\Http\Controllers\Api\School\FileController;
use App\Http\Controllers\Api\School\GradeController;
use App\Http\Controllers\Api\School\MonthlyStatusController;
use App\Http\Controllers\Api\School\PaymentController;
use App\Http\Controllers\Api\School\RevenueController;
use App\Http\Controllers\Api\School\RoomController;
use App\Http\Controllers\Api\School\StudentImportController;
use App\Http\Controllers\Api\School\StudentProfileController;
use App\Http\Controllers\Api\School\CoursePaymentController;
use App\Http\Controllers\Api\School\TeacherCommissionController;
use App\Http\Controllers\Api\School\StaffExpenseController;
use App\Http\Controllers\Api\School\PayrollController;
use App\Http\Controllers\Api\School\AccountingController;
use App\Http\Controllers\Api\School\TimetableSlotController;
use App\Http\Controllers\Api\System\ActivityLogController;
use App\Http\Controllers\Api\System\SystemAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Public ───────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register-school', [AuthController::class, 'register']);
        Route::post('/login',           [AuthController::class, 'login']);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('/reset-password',  [PasswordResetController::class, 'resetPassword']);
    });

    // ── Authenticated ─────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum'])->group(function () {

        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me',      [AuthController::class, 'me']);
        Route::patch('/auth/me',    [AuthController::class, 'updateProfile']);

        // ── System Admin ──────────────────────────────────────────────────────
        Route::middleware(['role:system_admin'])->prefix('system')->group(function () {
            Route::get('/dashboard',         [SystemAdminController::class, 'dashboard']);
            Route::get('/stats',             [SystemAdminController::class, 'stats']);

            Route::get('/schools',              [SystemAdminController::class, 'schools']);
            Route::get('/schools/{school}',     [SystemAdminController::class, 'showSchool']);
            Route::patch('/schools/{school}',   [SystemAdminController::class, 'updateSchool']);
            Route::delete('/schools/{school}',  [SystemAdminController::class, 'destroySchool']);
            Route::patch('/schools/{school}/subscription', [SystemAdminController::class, 'updateSubscription']);

            Route::get('/plans',                     [SystemAdminController::class, 'plans']);
            Route::post('/plans',                    [SystemAdminController::class, 'storePlan']);
            Route::patch('/plans/{subscriptionPlan}', [SystemAdminController::class, 'updatePlan']);

            Route::apiResource('banking-info', BankingInfoController::class)->except(['show']);
            Route::get('subscription-requests/pending-count', [SubscriptionRequestController::class, 'pendingCount']);
            Route::get('subscription-requests', [SubscriptionRequestController::class, 'index']);
            Route::post('subscription-requests/{subscriptionRequest}/approve', [SubscriptionRequestController::class, 'approve']);
            Route::post('subscription-requests/{subscriptionRequest}/reject', [SubscriptionRequestController::class, 'reject']);

            Route::get('/activity-logs', [ActivityLogController::class, 'systemLogs']);

            // System settings (trial period, etc.)
            Route::get('/settings',         [SystemAdminController::class, 'getSettings']);
            Route::patch('/settings/{key}', [SystemAdminController::class, 'updateSetting']);
        });

        // ── Tenant-scoped ─────────────────────────────────────────────────────
        Route::middleware([
            \App\Http\Middleware\SetTenantContext::class,
            \App\Http\Middleware\EnsureSchoolIsActive::class,
        ])->group(function () {

            // School Owner + Admin
            Route::middleware(['role:school_owner|admin'])->prefix('school')->group(function () {
                // School profile
                Route::get('/',   [SchoolController::class, 'show']);
                Route::patch('/', [SchoolController::class, 'update']);

                // Dashboard
                Route::get('/dashboard', DashboardController::class);

                // Activity logs (school-level)
                Route::get('/activity-logs', [ActivityLogController::class, 'schoolLogs']);

                // Users
                Route::apiResource('users', UserController::class);

                // Grades
                Route::apiResource('grades', GradeController::class);

                // Rooms
                Route::apiResource('rooms', RoomController::class);

                // Classrooms
                Route::apiResource('classes', ClassroomController::class);
                Route::post('classes/{classroom}/enroll',              [ClassroomController::class, 'enroll']);
                Route::delete('classes/{classroom}/students/{student}', [ClassroomController::class, 'unenroll']);
                Route::get('classes/{classroom}/students',              [ClassroomController::class, 'students']);
                Route::get('classes/{classroom}/timetable',             [TimetableSlotController::class, 'forClassroom']);

                // Timetable
                Route::apiResource('timetable', TimetableSlotController::class);

                // Payments
                Route::get('payments',                          [PaymentController::class, 'index']);
                Route::post('payments',                         [PaymentController::class, 'store']);
                Route::get('payments/{payment}',                [PaymentController::class, 'show']);
                Route::patch('payments/{payment}',              [PaymentController::class, 'update']);
                Route::delete('payments/{payment}',             [PaymentController::class, 'destroy']);

                // ── Course-school routes ──────────────────────────────────────
                Route::prefix('course')->group(function () {
                    // Courses + levels
                    Route::apiResource('courses', CourseController::class);
                    Route::post('courses/{course}/levels',                   [CourseLevelController::class, 'store']);
                    Route::patch('courses/{course}/levels/{level}',          [CourseLevelController::class, 'update']);
                    Route::delete('courses/{course}/levels/{level}',         [CourseLevelController::class, 'destroy']);

                    // Course classes + sessions (parameters() maps {courseClass} → $courseClass)
                    Route::get('classes/availability',                      [CourseClassController::class, 'checkAvailability']);
                    // Distinct route names ('course.classes.*') so they don't collide
                    // with the regular classrooms apiResource ('classes.*') — required
                    // for route:cache to serialize (names must be unique).
                    Route::apiResource('classes', CourseClassController::class)
                        ->parameters(['classes' => 'courseClass'])
                        ->names('course.classes');
                    Route::put('classes/{courseClass}/sessions',             [CourseClassController::class, 'updateSessions']);
                    Route::get('classes/{courseClass}/monthly-status',       [MonthlyStatusController::class, 'forClass']);

                    // Enroll / unenroll
                    Route::post('classes/{courseClass}/enroll',              [CourseEnrollmentController::class, 'enroll']);
                    Route::apiResource('enrollments', CourseEnrollmentController::class)->except(['store']);

                    // Monthly status overrides
                    Route::post('enrollments/{enrollment}/monthly-status',   [MonthlyStatusController::class, 'upsert']);
                    Route::delete('monthly-status/{monthlyStatus}',          [MonthlyStatusController::class, 'destroy']);

                    // Teacher commissions
                    Route::apiResource('commissions', TeacherCommissionController::class)->except(['show']);

                    // Additional charges
                    Route::apiResource('charges', AdditionalChargeController::class)->except(['show']);

                    // Course payments
                    Route::get('payments/unpaid',         [CoursePaymentController::class, 'unpaid']);
                    Route::get('payments',                [CoursePaymentController::class, 'index']);
                    Route::post('payments',               [CoursePaymentController::class, 'store']);
                    Route::patch('payments/{coursePayment}', [CoursePaymentController::class, 'update']);
                    Route::delete('payments/{coursePayment}', [CoursePaymentController::class, 'destroy']);

                    // Revenue
                    Route::get('revenue',        [RevenueController::class, 'summary']);
                    Route::get('revenue/export', [RevenueController::class, 'export']);
                });
                // ── End course-school routes ──────────────────────────────────

                // Students (profiles — admin/owner only for update)
                Route::post('students/import',            [StudentImportController::class, 'import']);
                Route::get('students',                    [StudentProfileController::class, 'index']);
                Route::get('students/{student}',          [StudentProfileController::class, 'show']);
                Route::patch('students/{student}',        [StudentProfileController::class, 'update']);
                Route::get('students/{student}/payments', [PaymentController::class, 'studentSummary']);
                Route::get('students/{student}/classes',  [StudentProfileController::class, 'classes']);

                // (Attendance routes moved to shared owner|admin|teacher group below)

                // Staff expenses (general: transport, supplies, etc.)
                Route::get('expenses/report',          [StaffExpenseController::class, 'report']);
                Route::apiResource('expenses',         StaffExpenseController::class)->except(['show']);

                // Payroll (salaries, advances, bonuses)
                Route::get('payroll',                          [PayrollController::class, 'index']);
                Route::get('payroll/salary-preview',           [PayrollController::class, 'salaryPreview']);
                Route::post('payroll/generate',                [PayrollController::class, 'generate']);
                Route::post('payroll/mark-paid',               [PayrollController::class, 'markMonthPaid']);
                Route::get('payroll/export',                   [PayrollController::class, 'exportCsv']);
                Route::post('payroll',                         [PayrollController::class, 'store']);
                Route::patch('payroll/{payrollEntry}',         [PayrollController::class, 'update']);
                Route::delete('payroll/{payrollEntry}',        [PayrollController::class, 'destroy']);

                // Accounting
                Route::get('accounting/monthly',               [AccountingController::class, 'monthly']);
                Route::get('accounting/yearly',                [AccountingController::class, 'yearly']);
                Route::get('accounting/journal',               [AccountingController::class, 'journal']);
                Route::get('accounting/export/monthly',        [AccountingController::class, 'exportMonthly']);
                Route::get('accounting/export/yearly',         [AccountingController::class, 'exportYearly']);
            });

            // Teachers – view-only timetable + their students
            Route::middleware(['role:teacher'])->prefix('school')->group(function () {
                Route::get('/my-timetable', function (\Illuminate\Http\Request $request) {
                    return response()->json(
                        \App\Models\TimetableSlot::where('school_id', $request->user()->school_id)
                            ->where('teacher_id', $request->user()->id)
                            ->with(['classroom.grade', 'room'])
                            ->orderBy('day_of_week')
                            ->orderBy('start_time')
                            ->get()
                    );
                });
                Route::get('/classes/{classroom}/timetable', [TimetableSlotController::class, 'forClassroom']);
                Route::get('/classes/{classroom}/students',  [ClassroomController::class, 'students']);
            });

            // ── Attendance — director, admin AND teachers can all record/view ──
            // Defined ONCE (no duplicates) to avoid last-registered-wins override.
            Route::middleware(['role:school_owner|admin|teacher'])->prefix('school')->group(function () {
                Route::get('attendance/report/monthly',  [AttendanceController::class, 'reportMonthly']);
                Route::get('attendance/report/level',    [AttendanceController::class, 'reportLevel']);
                Route::get('attendance',                 [AttendanceController::class, 'index']);
                Route::post('attendance/bulk',           [AttendanceController::class, 'bulk']);
            });

            // Students – own timetable
            Route::middleware(['role:student'])->prefix('school')->group(function () {
                Route::get('/my-timetable', function (\Illuminate\Http\Request $request) {
                    $classroomIds = $request->user()->enrolledClasses()->pluck('classrooms.id');
                    return response()->json(
                        \App\Models\TimetableSlot::where('school_id', $request->user()->school_id)
                            ->whereIn('classroom_id', $classroomIds)
                            ->with(['classroom.grade', 'teacher:id,name', 'room'])
                            ->orderBy('day_of_week')
                            ->orderBy('start_time')
                            ->get()
                    );
                });
            });

            // Files: teachers can upload; all roles can list/view/download
            Route::middleware(['role:school_owner|admin|teacher|student'])->prefix('school')->group(function () {
                Route::get('files',                  [FileController::class, 'index']);
                Route::post('files',                 [FileController::class, 'store']);
                Route::get('files/{file}',           [FileController::class, 'show']);
                Route::patch('files/{file}',         [FileController::class, 'update']);
                Route::delete('files/{file}',        [FileController::class, 'destroy']);
                Route::get('files/{file}/download',  [FileController::class, 'download']);
            });

            // Teacher timetable (admin/owner can also query by teacher)
            Route::middleware(['role:school_owner|admin'])->prefix('school')->group(function () {
                Route::get('/teachers/{teacher}/timetable', function (
                    \Illuminate\Http\Request $request,
                    \App\Models\User $teacher
                ) {
                    abort_if($teacher->school_id !== $request->user()->school_id, 403);
                    return response()->json(
                        \App\Models\TimetableSlot::where('school_id', $teacher->school_id)
                            ->where('teacher_id', $teacher->id)
                            ->with(['classroom.grade', 'room'])
                            ->orderBy('day_of_week')
                            ->orderBy('start_time')
                            ->get()
                    );
                });
            });
        });
    });

    // ── Subscription routes (accessible even when subscription expired) ────────
    Route::middleware(['auth:sanctum', 'role:school_owner|admin', \App\Http\Middleware\SetTenantContext::class])
        ->prefix('school/subscription')
        ->group(function () {
            Route::get('/plans',        [SubscriptionController::class, 'plans']);
            Route::get('/banking-info', [SubscriptionController::class, 'bankingInfo']);
            Route::get('/current',      [SubscriptionController::class, 'current']);
            Route::post('/request',     [SubscriptionController::class, 'createRequest']);
            Route::post('/request/{subscriptionRequest}/upload-proof', [SubscriptionController::class, 'uploadProof']);
        });

    // ── Expiry status (accessible even when subscription expired) ────────────
    Route::middleware(['auth:sanctum', 'role:school_owner|admin', \App\Http\Middleware\SetTenantContext::class])
        ->get('school/expiry-status', function (\Illuminate\Http\Request $request) {
            $school = $request->user()->school;
            if (!$school || !$school->subscription_ends_at) {
                return response()->json(['days_remaining' => null, 'status' => 'no_subscription']);
            }
            $days   = (int) now()->diffInDays($school->subscription_ends_at, false);
            $status = $days < 0 ? 'expired' : ($days <= 7 ? 'critical' : ($days <= 30 ? 'warning' : 'ok'));
            return response()->json(['days_remaining' => $days, 'status' => $status]);
        });
});
