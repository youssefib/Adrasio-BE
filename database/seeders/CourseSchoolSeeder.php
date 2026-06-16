<?php

namespace Database\Seeders;

use App\Models\AdditionalCharge;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseEnrollment;
use App\Models\CourseLevel;
use App\Models\CoursePayment;
use App\Models\MonthlyEnrollmentStatus;
use App\Models\PayrollEntry;
use App\Models\Room;
use App\Models\School;
use App\Models\StaffExpense;
use App\Models\StudentProfile;
use App\Models\SubscriptionPlan;
use App\Models\TeacherCommission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Cours de Soutien Seeder — Centre Ibn Rochd (Rabat)
 * ────────────────────────────────────────────────────
 * A realistic Moroccan tutoring centre with:
 *   • 6 subjects: Maths, PC, SVT, Français, Arabe, Anglais
 *   • 1 class per primary level (Maths 1AP → Maths 6AP)
 *   • Main middle-school subjects per level (1AC–3AC): Maths + Français + Arabe
 *   • Main high-school subjects per level (TC–2BAC):  Maths + PC + SVT + Français
 *   • Anglais: Débutant / Intermédiaire / Avancé
 *   • 29 course classes — all teacher & room conflicts verified
 *   • 9 teachers (varied salary types) + 1 owner
 *   • 70 students with multi-subject enrollments
 *   • 4 months of course payment history (Mar–Jun 2026)
 *   • Commissions, attendance, payroll, expenses
 */
class CourseSchoolSeeder extends Seeder
{
    // Payment months to seed (last 4 months)
    private const PAYMENT_MONTHS = [
        [2026, 3], [2026, 4], [2026, 5], [2026, 6],
    ];

    public function run(): void
    {
        if (School::where('slug', 'ibn-rochd-soutien')->exists()) {
            $this->command->info('[CourseSchoolSeeder] Already seeded — skipping.');
            return;
        }

        $this->command->info('[CourseSchoolSeeder] Seeding Centre Ibn Rochd…');

        $plan = SubscriptionPlan::where('slug', 'pro')->first()
            ?? SubscriptionPlan::first();

        // ── School ────────────────────────────────────────────────────────────
        $school = School::create([
            'subscription_plan_id' => $plan?->id,
            'subscription_tier'    => 'tier2',
            'name'                 => 'Centre Ibn Rochd — Cours de Soutien',
            'slug'                 => 'ibn-rochd-soutien',
            'email'                => 'contact@ibn-rochd-soutien.ma',
            'phone'                => '+212 537 70 00 00',
            'address'              => '12 Avenue Mohammed V, Agdal',
            'city'                 => 'Rabat',
            'country'              => 'MA',
            'timezone'             => 'Africa/Casablanca',
            'status'               => 'active',
            'school_type'          => 'course',
            'trial_ends_at'        => null,
            'subscription_ends_at' => now()->addYear()->toDateTimeString(),
        ]);

        // ── Owner ─────────────────────────────────────────────────────────────
        $owner = User::create([
            'school_id'            => $school->id,
            'role'                 => 'school_owner',
            'name'                 => 'M. Rachid Benmoussa',
            'email'                => 'directeur@ibn-rochd-soutien.ma',
            'phone'                => '+212 661 70 00 01',
            'password'             => Hash::make('Demo@12345'),
            'is_active'            => true,
            'base_salary'          => 7500.00,
            'salary_type'          => 'fixed',
            'salary_variable_rate' => null,
        ]);
        $owner->assignRole('school_owner');

        // ── Rooms (4) ─────────────────────────────────────────────────────────
        $rooms = [];
        foreach ([
            ['Salle A', 'A', 14, true],
            ['Salle B', 'B', 14, true],
            ['Salle C', 'C', 12, true],
            ['Salle D', 'D', 10, true],
        ] as [$name, $code, $cap, $avail]) {
            $rooms[] = Room::create([
                'school_id'    => $school->id,
                'name'         => $name,
                'code'         => $code,
                'capacity'     => $cap,
                'is_available' => $avail,
            ]);
        }
        [$rA, $rB, $rC, $rD] = $rooms;

        // ── Teachers (9) ─────────────────────────────────────────────────────
        // [name, email, phone, base_salary, salary_type, variable_rate, rate_is_pct]
        $teacherData = [
            // 0 — Khadija: primary Maths 1AP-4AP, fixed
            ['Mme Khadija Belkadi',  'khadija@ibn-rochd.ma',   '+212 661 71 00 01', 2200, 'fixed',                null,  false],
            // 1 — Rachida: primary Maths 5AP-6AP, fixed
            ['Mme Rachida Amrani',   'rachida@ibn-rochd.ma',    '+212 661 71 00 02', 2000, 'fixed',                null,  false],
            // 2 — Youssef: Maths middle, base + 50 MAD/student
            ['M. Youssef Meskini',   'youssef@ibn-rochd.ma',    '+212 661 71 00 03', 1800, 'base_plus_per_student', 50,   false],
            // 3 — Nadia: Français middle+high, base + 45 MAD/student
            ['Mme Nadia Bouzidi',    'nadia@ibn-rochd.ma',      '+212 661 71 00 04', 1800, 'base_plus_per_student', 45,   false],
            // 4 — Karim: Maths high, base + 65 MAD/student
            ['M. Karim Alaoui',      'karim@ibn-rochd.ma',      '+212 661 71 00 05', 2000, 'base_plus_per_student', 65,   false],
            // 5 — Hassan: PC, base + 55 MAD/student
            ['M. Hassan Tazi',       'hassan@ibn-rochd.ma',     '+212 661 71 00 06', 1800, 'base_plus_per_student', 55,   false],
            // 6 — Omar: Arabe middle, base + 280 MAD/class
            ['M. Omar Lahlou',       'omar@ibn-rochd.ma',       '+212 661 71 00 07', 1500, 'base_plus_per_class',  280,   false],
            // 7 — Samira: Anglais, fixed
            ['Mme Samira Moujahid',  'samira@ibn-rochd.ma',     '+212 661 71 00 08', 2600, 'fixed',                null,  false],
            // 8 — Imane: SVT high, base + 50 MAD/student
            ['Mme Imane Senhaji',    'imane@ibn-rochd.ma',      '+212 661 71 00 09', 1700, 'base_plus_per_student', 50,   false],
        ];

        $teachers = [];
        foreach ($teacherData as [$name, $email, $phone, $base, $salType, $rate, $isPct]) {
            $t = User::create([
                'school_id'                 => $school->id,
                'role'                      => 'teacher',
                'name'                      => $name,
                'email'                     => $email,
                'phone'                     => $phone,
                'password'                  => Hash::make('Demo@12345'),
                'is_active'                 => true,
                'base_salary'               => $base,
                'salary_type'               => $salType,
                'salary_variable_rate'      => $rate,
                'salary_rate_is_percentage' => $isPct,
            ]);
            $t->assignRole('teacher');
            $teachers[] = $t;
        }
        [$tKhadija,$tRachida,$tYoussef,$tNadia,$tKarim,$tHassan,$tOmar,$tSamira,$tImane] = $teachers;

        // ── Courses + Levels ──────────────────────────────────────────────────
        // keys: maths, pc, svt, fr, ar, en
        $courseDefs = [
            'maths' => ['name' => 'Mathématiques',      'color' => '#3b82f6', 'levels' => [
                '1AP','2AP','3AP','4AP','5AP','6AP',
                '1AC','2AC','3AC',
                'TC','1BAC','2BAC Sciences Maths','2BAC Sciences Physiques',
            ]],
            'pc'    => ['name' => 'Physique-Chimie',     'color' => '#10b981', 'levels' => [
                '3AC','TC','1BAC','2BAC Sciences Physiques',
            ]],
            'svt'   => ['name' => 'Sciences de la Vie', 'color' => '#22c55e', 'levels' => [
                'TC','1BAC','2BAC Sciences Naturelles',
            ]],
            'fr'    => ['name' => 'Français',            'color' => '#f59e0b', 'levels' => [
                '1AC','2AC','3AC','TC','1BAC','2BAC',
            ]],
            'ar'    => ['name' => 'Langue Arabe',        'color' => '#ef4444', 'levels' => [
                '1AC','2AC','3AC',
            ]],
            'en'    => ['name' => 'Anglais',             'color' => '#8b5cf6', 'levels' => [
                'Débutant','Intermédiaire','Avancé',
            ]],
        ];

        $courseModels = []; // key => Course
        $levelMap     = []; // "key|levelName" => CourseLevel
        foreach ($courseDefs as $key => $cd) {
            $course = Course::create([
                'school_id'   => $school->id,
                'name'        => $cd['name'],
                'description' => "Cours de soutien — {$cd['name']} — tous niveaux",
                'color'       => $cd['color'],
            ]);
            $courseModels[$key] = $course;
            foreach ($cd['levels'] as $order => $lvlName) {
                $level = CourseLevel::create([
                    'school_id' => $school->id,
                    'course_id' => $course->id,
                    'name'      => $lvlName,
                    'order'     => $order + 1,
                ]);
                $levelMap["{$key}|{$lvlName}"] = $level;
            }
        }

        // ── Course Classes (29) ───────────────────────────────────────────────
        // Fully conflict-checked: no teacher and no room overlap anywhere.
        //
        // Schedule legend:
        //   Primary (Sam/Dim only — school day for primary parents)
        //   Middle  (late afternoon weekdays + Sam afternoon)
        //   High    (morning weekdays + Mer/Sam mornings)
        //   Anglais (Tue/Thu evenings + Sun afternoon)
        //
        // [name, course_key, level_name, teacher_idx, room, fee, capacity, [[day,H:i,H:i], ...]]
        // days: 1=Lun 2=Mar 3=Mer 4=Jeu 5=Ven 6=Sam 7=Dim
        $classDefs = [
            // ── Primary Maths (0-5) ── Sam morning back-to-back, Dim morning back-to-back
            ['Maths 1AP', 'maths', '1AP', 0, $rA, 200, 10, [[6, '09:00', '10:30']]],
            ['Maths 2AP', 'maths', '2AP', 0, $rA, 200, 10, [[6, '10:30', '12:00']]],
            ['Maths 3AP', 'maths', '3AP', 1, $rB, 220, 10, [[6, '09:00', '10:30']]],
            ['Maths 4AP', 'maths', '4AP', 1, $rB, 220, 10, [[6, '10:30', '12:00']]],
            ['Maths 5AP', 'maths', '5AP', 0, $rC, 240, 10, [[7, '09:00', '10:30']]],
            ['Maths 6AP', 'maths', '6AP', 0, $rC, 240, 10, [[7, '10:30', '12:00']]],

            // ── Middle Maths (6-8) ── Youssef (teacher 2)
            ['Maths 1AC', 'maths', '1AC', 2, $rA, 280, 14, [[1, '17:00', '19:00'], [4, '17:00', '19:00']]],
            ['Maths 2AC', 'maths', '2AC', 2, $rB, 280, 14, [[2, '17:00', '19:00'], [5, '17:00', '19:00']]],
            ['Maths 3AC', 'maths', '3AC', 2, $rC, 300, 14, [[3, '15:00', '17:00'], [6, '15:00', '17:00']]],

            // ── Middle Français (9-11) ── Nadia (teacher 3)
            ['Français 1AC', 'fr', '1AC', 3, $rA, 250, 14, [[1, '15:00', '17:00'], [4, '15:00', '17:00']]],
            ['Français 2AC', 'fr', '2AC', 3, $rB, 250, 14, [[2, '15:00', '17:00'], [5, '15:00', '17:00']]],
            ['Français 3AC', 'fr', '3AC', 3, $rD, 260, 14, [[3, '13:00', '15:00'], [6, '13:00', '15:00']]],

            // ── Middle Arabe (12-14) ── Omar (teacher 6)
            ['Arabe 1AC', 'ar', '1AC', 6, $rC, 240, 14, [[1, '13:00', '15:00'], [4, '13:00', '15:00']]],
            ['Arabe 2AC', 'ar', '2AC', 6, $rD, 240, 14, [[2, '13:00', '15:00'], [5, '13:00', '15:00']]],
            ['Arabe 3AC', 'ar', '3AC', 6, $rC, 260, 14, [[3, '17:00', '19:00'], [6, '17:00', '19:00']]],

            // ── High Maths (15-17) ── Karim (teacher 4)
            ['Maths TC',   'maths', 'TC',                  4, $rA, 320, 14, [[1, '09:00', '11:00'], [4, '09:00', '11:00']]],
            ['Maths 1BAC', 'maths', '1BAC',                4, $rB, 340, 14, [[2, '09:00', '11:00'], [5, '09:00', '11:00']]],
            ['Maths 2BAC', 'maths', '2BAC Sciences Maths', 4, $rC, 360, 14, [[3, '09:00', '11:00'], [6, '09:00', '11:00']]],

            // ── High PC (18-20) ── Hassan (teacher 5)
            ['PC TC',   'pc', 'TC',                       5, $rB, 300, 14, [[1, '11:00', '13:00'], [4, '11:00', '13:00']]],
            ['PC 1BAC', 'pc', '1BAC',                     5, $rA, 320, 14, [[2, '11:00', '13:00'], [5, '11:00', '13:00']]],
            ['PC 2BAC', 'pc', '2BAC Sciences Physiques',  5, $rD, 340, 14, [[3, '11:00', '13:00'], [6, '11:00', '13:00']]],

            // ── High SVT (21-23) ── Imane (teacher 8)
            ['SVT TC',   'svt', 'TC',                       8, $rA, 280, 12, [[2, '13:00', '15:00'], [5, '13:00', '15:00']]],
            ['SVT 1BAC', 'svt', '1BAC',                     8, $rB, 300, 12, [[1, '13:00', '15:00'], [4, '13:00', '15:00']]],
            ['SVT 2BAC', 'svt', '2BAC Sciences Naturelles', 8, $rC, 320, 12, [[1, '11:00', '13:00'], [4, '11:00', '13:00']]],

            // ── High Français (24-25) ── Nadia (teacher 3) — no conflict with middle slots
            ['Français 1BAC', 'fr', '1BAC', 3, $rA, 280, 14, [[2, '17:00', '19:00'], [5, '17:00', '19:00']]],
            ['Français 2BAC', 'fr', '2BAC', 3, $rB, 280, 14, [[1, '17:00', '19:00'], [4, '17:00', '19:00']]],

            // ── Anglais (26-28) ── Samira (teacher 7)
            ['Anglais Débutant',     'en', 'Débutant',     7, $rD, 280, 10, [[2, '18:00', '20:00']]],
            ['Anglais Intermédiaire','en', 'Intermédiaire', 7, $rD, 300, 10, [[4, '18:00', '20:00']]],
            ['Anglais Avancé',       'en', 'Avancé',        7, $rC, 320, 10, [[7, '15:00', '17:00']]],
        ];

        $courseClasses = [];
        foreach ($classDefs as [$cname, $courseKey, $levelName, $teacherIdx, $room, $fee, $cap, $sessions]) {
            $course   = $courseModels[$courseKey];
            $levelKey = "{$courseKey}|{$levelName}";
            $level    = $levelMap[$levelKey] ?? null;

            $class = CourseClass::create([
                'school_id'       => $school->id,
                'course_id'       => $course->id,
                'course_level_id' => $level?->id,
                'teacher_id'      => $teachers[$teacherIdx]->id,
                'room_id'         => $room->id,
                'name'            => $cname,
                'monthly_fee'     => $fee,
                'capacity'        => $cap,
                'status'          => 'active',
            ]);

            foreach ($sessions as [$day, $start, $end]) {
                $startC = Carbon::createFromFormat('H:i', $start);
                $endC   = Carbon::createFromFormat('H:i', $end);
                ClassSession::create([
                    'course_class_id'  => $class->id,
                    'day_of_week'      => $day,
                    'start_time'       => $start . ':00',
                    'end_time'         => $end . ':00',
                    'duration_minutes' => $startC->diffInMinutes($endC),
                    'room_id'          => null,
                ]);
            }

            $courseClasses[] = $class;
        }

        // ── Teacher Commissions ───────────────────────────────────────────────
        // [teacher_idx, class_idx (null=default), commission_type, amount]
        $commDefs = [
            [0, null, 'per_student', 55],   // Khadija — primary
            [1, null, 'per_student', 50],   // Rachida — primary
            [2, null, 'per_student', 50],   // Youssef — Maths middle
            [3, null, 'per_student', 45],   // Nadia — Français
            [4, 15,   'per_student', 70],   // Karim — Maths TC (override)
            [4, 16,   'per_student', 75],   // Karim — Maths 1BAC (override)
            [4, 17,   'per_student', 80],   // Karim — Maths 2BAC (override)
            [4, null, 'per_student', 65],   // Karim — default
            [5, null, 'per_student', 55],   // Hassan — PC
            [6, null, 'per_class',   300],  // Omar — Arabe (per class)
            [7, null, 'fixed_monthly', 0],  // Samira — fixed salary, no commission
            [8, null, 'per_student', 50],   // Imane — SVT
        ];
        foreach ($commDefs as [$ti, $ci, $type, $amount]) {
            if ($amount === 0) continue; // skip zero-amount commission
            TeacherCommission::create([
                'school_id'       => $school->id,
                'teacher_id'      => $teachers[$ti]->id,
                'course_class_id' => $ci !== null ? $courseClasses[$ci]->id : null,
                'commission_type' => $type,
                'amount'          => $amount,
                'effective_from'  => now()->subMonths(6)->startOfMonth()->toDateString(),
                'effective_to'    => null,
            ]);
        }

        // ── Students (70) ─────────────────────────────────────────────────────
        $studentNames = [
            // Primary (0-14)
            'Amira Benali','Hamza Ouali','Lina Tahiri','Adam Filali','Rania Ziani',
            'Rayane Berrada','Sana Lahlou','Othmane Fassi','Douha Qabbaj','Mehdi Mansouri',
            'Nour Rhali','Ilias Kettani','Sara Ouazzani','Badr Mekki','Imane Alaoui',
            // Middle 1AC (15-24)
            'Youssef Alami','Fatima Bensouda','Amine Sabiri','Zineb Guerraoui','Omar Sebti',
            'Hind Laaroussi','Saad Berrada','Sana Kettani','Walid Lamrani','Leila Tazi',
            // Middle 2AC (25-34)
            'Bilal Hafidi','Meriem Chraibi','Khalid Idrissi','Houda Slimani','Ismail Benkirane',
            'Rima Benhima','Achraf Filali','Kenza Rahhali','Driss Ouali','Najat Sqalli',
            // Middle 3AC (35-44)
            'Tariq Senhadji','Amina Boutaleb','Badr Lazrak','Siham El Fassi','Jawad Tlemcani',
            'Widad Kharbouch','Hamid Benabdallah','Safaa Mouline','Rachid Mansouri','Loubna Laaziz',
            // High TC (45-52)
            'Anass Belghiti','Ghita Moujahid','Hicham El Amrani','Btissam Slaoui',
            'Mounir Senhaji','Soukaina Chraibi','Karim Qasimi','Mariam Lahlou',
            // High 1BAC (53-60)
            'Adil El Ouafi','Hafsa Benali','Yassine Kettani','Chaimaa Tazi',
            'Ayman Guerraoui','Fadwa Zemmouri','Nabil Alami','Roumaissae Idrissi',
            // High 2BAC (61-68)
            'Ibrahim Bensouda','Sanaa El Fassi','Khalid Lahlou','Meryem Filali',
            'Tarik Berrada','Btissam Rahhali','Anas Ouazzani','Salma Chraibi',
            // Anglais extra (69)
            'Charlotte Dupont',
        ];

        $students = [];
        foreach ($studentNames as $i => $name) {
            $parts = explode(' ', $name);
            $first = strtolower($parts[0]);
            $last  = strtolower($parts[1] ?? 'x');
            $email = "{$first}.{$last}@student.ibn-rochd.ma";
            if (User::where('email', $email)->exists()) {
                $email = "{$first}.{$last}{$i}@student.ibn-rochd.ma";
            }

            $u = User::create([
                'school_id' => $school->id,
                'role'      => 'student',
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make('Demo@12345'),
                'is_active' => true,
            ]);
            $u->assignRole('student');

            // Age estimation based on level group
            $birthYear = match(true) {
                $i < 15  => rand(2014, 2018), // primary
                $i < 25  => rand(2011, 2013), // 1AC
                $i < 35  => rand(2010, 2012), // 2AC
                $i < 45  => rand(2009, 2011), // 3AC
                $i < 53  => rand(2008, 2010), // TC
                $i < 61  => rand(2007, 2009), // 1BAC
                default  => rand(2006, 2008), // 2BAC / Anglais
            };
            $dob = Carbon::createFromDate($birthYear, rand(1, 12), rand(1, 28))->toDateString();

            $profile = StudentProfile::create([
                'user_id'           => $u->id,
                'school_id'         => $school->id,
                'enrollment_number' => 'IBR-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'date_of_birth'     => $dob,
                'guardian_name'     => 'Parent de ' . $parts[0],
                'guardian_phone'    => '+212 6 6' . rand(1000000, 9999999),
                'status'            => 'active',
            ]);
            $students[] = $profile;
        }

        // ── Enrollments ───────────────────────────────────────────────────────
        // Primary: each student in exactly 1 Maths AP class
        // Middle: each student in Maths + Français + Arabe for their AC level
        // High TC: Maths TC + PC TC + SVT TC
        // High 1BAC: Maths 1BAC + PC 1BAC + SVT 1BAC + Français 1BAC
        // High 2BAC: Maths 2BAC + PC 2BAC + SVT 2BAC + Français 2BAC
        // Anglais: selected students across levels

        // [student_indices_range_start, student_indices_range_end, class_indices]
        $groups = [
            // Primary — 2-3 students per AP class
            [[0, 2],   [0]],   // Maths 1AP
            [[2, 4],   [1]],   // Maths 2AP
            [[5, 7],   [2]],   // Maths 3AP
            [[7, 9],   [3]],   // Maths 4AP
            [[10, 12], [4]],   // Maths 5AP
            [[12, 15], [5]],   // Maths 6AP
            // 1AC — each in Maths + Français + Arabe 1AC
            [[15, 25], [6, 9, 12]],
            // 2AC — each in Maths + Français + Arabe 2AC
            [[25, 35], [7, 10, 13]],
            // 3AC — each in Maths + Français + Arabe 3AC
            [[35, 45], [8, 11, 14]],
            // TC — each in Maths + PC + SVT
            [[45, 53], [15, 18, 21]],
            // 1BAC — each in Maths + PC + SVT + Français
            [[53, 61], [16, 19, 22, 24]],
            // 2BAC — each in Maths + PC + SVT + Français
            [[61, 69], [17, 20, 23, 25]],
            // Anglais — spread across levels
            [[20, 25], [26]],  // some 1AC students → Débutant
            [[30, 35], [27]],  // some 2AC students → Intermédiaire
            [[53, 58], [27]],  // some 1BAC students → Intermédiaire
            [[61, 66], [28]],  // some 2BAC students → Avancé
            [[69, 70], [26]],  // Charlotte → Débutant
        ];

        $enrollments = [];
        $enrolledAt  = now()->subMonths(5)->startOfMonth()->toDateString();

        foreach ($groups as [[$from, $to], $classIndices]) {
            for ($si = $from; $si < $to; $si++) {
                $profile = $students[$si];
                foreach ($classIndices as $ci) {
                    // Skip duplicate
                    if (CourseEnrollment::where('student_profile_id', $profile->id)
                        ->where('course_class_id', $courseClasses[$ci]->id)->exists()) {
                        continue;
                    }
                    $enrollment = CourseEnrollment::create([
                        'school_id'            => $school->id,
                        'student_profile_id'   => $profile->id,
                        'course_class_id'      => $courseClasses[$ci]->id,
                        'monthly_fee_override' => null,
                        'enrolled_at'          => $enrolledAt,
                        'status'               => rand(0, 9) < 9 ? 'active' : 'inactive',
                        'notes'                => null,
                    ]);
                    $enrollments[] = $enrollment;
                }
            }
        }

        // ── Monthly Enrollment Statuses (last 4 months) ───────────────────────
        $currentYear  = (int) now()->format('Y');
        $currentMonth = (int) now()->format('m');

        foreach ($enrollments as $enrollment) {
            for ($back = 3; $back >= 0; $back--) {
                $dt = Carbon::createFromDate($currentYear, $currentMonth)->subMonths($back);
                $y  = (int) $dt->format('Y');
                $m  = (int) $dt->format('n');
                if ($enrollment->enrolled_at > $dt->endOfMonth()->toDateString()) continue;
                // 12% inactive rate
                if (rand(1, 100) <= 12 && $enrollment->status === 'active') {
                    MonthlyEnrollmentStatus::firstOrCreate(
                        ['enrollment_id' => $enrollment->id, 'year' => $y, 'month' => $m],
                        ['status' => 'inactive', 'notes' => 'Absence mensuelle']
                    );
                }
            }
        }

        // ── Course Payments (last 4 months) ───────────────────────────────────
        foreach ($enrollments as $enrollment) {
            if ($enrollment->status !== 'active') continue;

            $class = $courseClasses[0]; // fallback
            foreach ($courseClasses as $cc) {
                if ($cc->id === $enrollment->course_class_id) { $class = $cc; break; }
            }
            $fee = (float) $class->monthly_fee;

            foreach (self::PAYMENT_MONTHS as $pmIdx => [$year, $month]) {
                $hash   = (($enrollment->student_profile_id * 13 + $pmIdx * 19) % 100);

                if ($pmIdx < 2) {
                    // Mar–Apr: mostly paid
                    $status = $hash < 78 ? 'paid' : ($hash < 90 ? 'pending' : 'waived');
                } else {
                    // May–Jun: more pending
                    $status = $hash < 55 ? 'paid' : ($hash < 85 ? 'pending' : 'waived');
                }

                $paidAt = null;
                if ($status === 'paid') {
                    $day    = 1 + (($enrollment->student_profile_id * 3 + $pmIdx * 7) % 20);
                    $paidAt = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }

                // Check monthly status — if inactive, waive
                $isInactive = MonthlyEnrollmentStatus::where('enrollment_id', $enrollment->id)
                    ->where('year', $year)->where('month', $month)
                    ->where('status', 'inactive')->exists();

                if ($isInactive) { $status = 'waived'; $paidAt = null; }

                CoursePayment::create([
                    'school_id'           => $school->id,
                    'course_enrollment_id'=> $enrollment->id,
                    'month'               => $month,
                    'year'                => $year,
                    'amount'              => $fee,
                    'status'              => $status,
                    'notes'               => $status === 'pending' ? 'En attente de règlement' : null,
                    'paid_at'             => $paidAt,
                    'recorded_by'         => $owner->id,
                ]);
            }
        }

        // ── Additional Charges ─────────────────────────────────────────────────
        $chargeTypes = [
            ["Frais d'inscription", 200],
            ['Matériel pédagogique', 80],
            ['Examen de niveau', 100],
            ['Concours blanc', 60],
            ['Cours de rattrapage', 150],
        ];
        for ($i = 0; $i < 25; $i++) {
            $enrollment = $enrollments[array_rand($enrollments)];
            [$desc, $amount] = $chargeTypes[array_rand($chargeTypes)];
            $isPaid     = rand(0, 1);
            $chargeDate = now()->subDays(rand(0, 90))->toDateString();
            AdditionalCharge::create([
                'school_id'          => $school->id,
                'student_profile_id' => $enrollment->student_profile_id,
                'enrollment_id'      => $enrollment->id,
                'description'        => $desc,
                'amount'             => $amount + rand(0, 30),
                'charge_date'        => $chargeDate,
                'status'             => $isPaid ? 'paid' : 'pending',
                'paid_at'            => $isPaid ? $chargeDate : null,
                'notes'              => null,
            ]);
        }

        // ── Attendance (last 8 weeks) ──────────────────────────────────────────
        $classBuckets = [];
        foreach ($enrollments as $e) {
            if ($e->status === 'active') {
                $classBuckets[$e->course_class_id][] = $e->student_profile_id;
            }
        }
        foreach ($courseClasses as $cc) {
            $sessions   = ClassSession::where('course_class_id', $cc->id)->get();
            $profileIds = $classBuckets[$cc->id] ?? [];
            if (empty($profileIds)) continue;

            for ($weekBack = 7; $weekBack >= 0; $weekBack--) {
                foreach ($sessions as $session) {
                    $date = Carbon::now()
                        ->subWeeks($weekBack)
                        ->startOfWeek(Carbon::MONDAY)
                        ->addDays($session->day_of_week - 1);

                    if ($date->isFuture()) continue;

                    foreach ($profileIds as $spId) {
                        $rand   = rand(1, 100);
                        $status = $rand <= 82 ? 'present' : ($rand <= 91 ? 'late' : ($rand <= 97 ? 'absent' : 'excused'));

                        Attendance::firstOrCreate(
                            [
                                'school_id'          => $school->id,
                                'class_type'         => 'course_class',
                                'course_class_id'    => $cc->id,
                                'student_profile_id' => $spId,
                                'date'               => $date->toDateString(),
                            ],
                            ['classroom_id' => null, 'status' => $status, 'notes' => null]
                        );
                    }
                }
            }
        }

        // ── Payroll + Expenses (school year Sept 2025 → Jun 2026) ────────────
        $now             = Carbon::now();
        $schoolYearStart = Carbon::create($now->year - 1, 9, 1)->startOfMonth();

        $allMonths = [];
        $cursor    = $schoolYearStart->copy();
        while ($cursor->lte($now)) {
            $allMonths[] = [$cursor->month, $cursor->year, $cursor->copy()];
            $cursor->addMonth();
        }

        // Leave May & June empty for testing the Generate button
        $skipPayroll = [[$now->year, 5], [$now->year, 6]];
        $payrollMonths = array_filter($allMonths, function ($m) use ($skipPayroll) {
            foreach ($skipPayroll as [$sy, $sm]) {
                if ($m[1] === $sy && $m[0] === $sm) return false;
            }
            return true;
        });

        foreach ($payrollMonths as [$month, $year, $monthDate]) {
            $paidAt = $monthDate->copy()->addDays(27);

            // Owner
            PayrollEntry::create([
                'school_id' => $school->id, 'user_id' => $owner->id,
                'month' => $month, 'year' => $year, 'type' => 'salary',
                'base_amount' => 7500, 'variable_amount' => 0, 'total_amount' => 7500,
                'description' => 'Salaire ' . $monthDate->format('m/Y'),
                'status' => 'paid', 'paid_at' => $paidAt, 'created_by' => $owner->id,
            ]);

            // Teachers — base only (variable part seeded as 0, Generate recalculates)
            foreach ($teachers as $teacher) {
                $base = (float) $teacher->base_salary;
                PayrollEntry::create([
                    'school_id' => $school->id, 'user_id' => $teacher->id,
                    'month' => $month, 'year' => $year, 'type' => 'salary',
                    'base_amount' => $base, 'variable_amount' => 0, 'total_amount' => $base,
                    'description' => 'Salaire ' . $monthDate->format('m/Y'),
                    'status' => 'paid', 'paid_at' => $paidAt, 'created_by' => $owner->id,
                ]);
            }

            // Advance every quarter for teacher[4] (Karim)
            if (in_array($month, [10, 1, 4])) {
                PayrollEntry::create([
                    'school_id' => $school->id, 'user_id' => $tKarim->id,
                    'month' => $month, 'year' => $year, 'type' => 'advance',
                    'base_amount' => 1000, 'variable_amount' => 0, 'total_amount' => 1000,
                    'description' => 'Avance sur salaire', 'status' => 'paid',
                    'paid_at' => $monthDate->copy()->addDays(5), 'created_by' => $owner->id,
                ]);
            }

            // Year-end bonus (December) for owner
            if ($month === 12) {
                PayrollEntry::create([
                    'school_id' => $school->id, 'user_id' => $owner->id,
                    'month' => $month, 'year' => $year, 'type' => 'bonus',
                    'base_amount' => 3000, 'variable_amount' => 0, 'total_amount' => 3000,
                    'description' => "Prime fin d'année", 'status' => 'paid',
                    'paid_at' => $monthDate->copy()->addDays(20), 'created_by' => $owner->id,
                ]);
            }
        }

        // General expenses
        $miscCategories = [
            ['transport',   'Remboursement transport — %s',   [80,  300]],
            ['supplies',    'Fournitures pédagogiques',        [50,  400]],
            ['equipment',   'Matériel de bureau / impression', [200, 800]],
            ['maintenance', 'Maintenance et réparations',      [150, 500]],
            ['other',       'Frais divers centre',             [50,  200]],
        ];
        $staffForExp = array_merge([$owner], $teachers);
        foreach ($allMonths as [$month, $year, $monthDate]) {
            $count = rand(3, 6);
            for ($i = 0; $i < $count; $i++) {
                [$cat, $descTpl, $range] = $miscCategories[array_rand($miscCategories)];
                $person  = $staffForExp[array_rand($staffForExp)];
                $expDate = $monthDate->copy()->addDays(rand(1, 25))->toDateString();
                $isPast  = $monthDate->lt($now->copy()->startOfMonth());
                $status  = $isPast ? 'paid' : (['pending', 'approved'][rand(0, 1)]);
                StaffExpense::create([
                    'school_id'    => $school->id,
                    'user_id'      => $person->id,
                    'category'     => $cat,
                    'description'  => sprintf($descTpl, $person->name),
                    'amount'       => rand($range[0], $range[1]),
                    'expense_date' => $expDate,
                    'status'       => $status,
                    'notes'        => null,
                ]);
            }
        }

        $this->command->info('');
        $this->command->info('✅ Centre Ibn Rochd seeded:');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Directeur (owner)',  'directeur@ibn-rochd-soutien.ma', 'Demo@12345'],
                ['Prof. Maths (high)', 'karim@ibn-rochd.ma',             'Demo@12345'],
                ['Prof. Français',     'nadia@ibn-rochd.ma',             'Demo@12345'],
                ['Élève (1AC)',        'youssef.alami@student.ibn-rochd.ma','Demo@12345'],
            ]
        );
        $this->command->info('   ' . count($courseClasses) . ' classes, ' . count($enrollments) . ' enrollments, ' . count($students) . ' students');
    }
}
