<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\File;
use App\Models\Grade;
use App\Models\Payment;
use App\Models\PayrollEntry;
use App\Models\Room;
use App\Models\School;
use App\Models\Attendance;
use App\Models\StaffExpense;
use App\Models\StudentProfile;
use App\Models\SubscriptionPlan;
use App\Models\TimetableSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Moroccan School Demo Seeder — Groupe Scolaire Averroès
 * ──────────────────────────────────────────────────────
 * Full primary-to-lycée private school in Casablanca:
 *   • 12 grades  — 1AP→6AP (primaire) + 1AC→3AC (collège) + TC→2BAC (lycée)
 *   • 14 rooms
 *   • 12 classrooms (1 per primary grade, 2 for 1AC, 1 each for 2AC/3AC/TC/1BAC/2BAC)
 *   • 15 teachers (6 primary class-teachers + 9 subject specialists)
 *   • ~63 students
 *   • Full timetable for 1AC-A and TC-A (conflict-verified)
 *   • 10-month payment history (Sept 2025 → Jun 2026)
 *   • Attendance, payroll, expenses, activity logs
 */
class MoroccanDemoSeeder extends Seeder
{
    private const ACADEMIC_YEAR  = '2025-2026';
    private const ENROLL_DATE    = '2025-09-02';
    private const PAYMENT_MONTHS = [
        [2025, 9], [2025, 10], [2025, 11], [2025, 12],
        [2026, 1], [2026, 2],  [2026, 3],  [2026, 4],
        [2026, 5], [2026, 6],
    ];

    public function run(): void
    {
        if (School::where('slug', 'gs-averroes')->exists()) {
            $this->command->info('[MoroccanDemoSeeder] Already seeded — skipping.');
            return;
        }

        $this->command->info('[MoroccanDemoSeeder] Seeding Groupe Scolaire Averroès…');

        DB::transaction(function () {
            $plan   = SubscriptionPlan::where('slug', 'pro')->first() ?? SubscriptionPlan::firstOrFail();
            $school = $this->createSchool($plan);
            $owner  = $this->createOwner($school);
            $admin  = $this->createAdmin($school);

            $teachers = $this->createTeachers($school);
            // Primary class-teachers: indices 0-5
            // Subject specialists: 6=Maths, 7=Français, 8=Arabe, 9=PC, 10=SVT, 11=HG, 12=EPS, 13=Anglais, 14=Info
            [$tP1,$tP2,$tP3,$tP4,$tP5,$tP6,$tMaths,$tFr,$tAr,$tPC,$tSVT,$tHG,$tEPS,$tEn,$tInfo] = $teachers;

            $rooms    = $this->createRooms($school);
            $grades   = $this->createGrades($school);
            $classes  = $this->createClassrooms($school, $grades, $teachers, $rooms);

            $this->createTimetable($school, $classes, $teachers, $rooms);

            $students = $this->createStudents($school, $classes);
            $this->createPayments($school, $students, $classes, $admin);
            $this->createFiles($school, $admin, $teachers, $grades, $classes);
            $this->createAttendance($school, $classes, $students);
            $this->createExpenses($school, $owner, $admin, $teachers);
            $this->createActivityLogs($school, $owner, $admin, $teachers);
        });

        $this->command->info('[MoroccanDemoSeeder] Done.');
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Directeur (owner)', 'directeur@gs-averroes.ma', 'Demo@12345'],
                ['Admin',             'admin@gs-averroes.ma',     'Demo@12345'],
                ['Prof. Maths',       'maths@gs-averroes.ma',     'Demo@12345'],
                ['Élève (1AC-A)',     'y.alaoui.s@gs-averroes.ma','Demo@12345'],
            ]
        );
    }

    // ── School ────────────────────────────────────────────────────────────────

    private function createSchool(SubscriptionPlan $plan): School
    {
        return School::create([
            'subscription_plan_id' => $plan->id,
            'subscription_tier'    => 'tier2',
            'name'                 => 'Groupe Scolaire Averroès',
            'slug'                 => 'gs-averroes',
            'email'                => 'contact@gs-averroes.ma',
            'phone'                => '+212 522 60 00 00',
            'address'              => 'Boulevard Ibn Rochd, Quartier Gauthier',
            'city'                 => 'Casablanca',
            'country'              => 'MA',
            'timezone'             => 'Africa/Casablanca',
            'status'               => 'active',
            'school_type'          => 'regular',
            'trial_ends_at'        => null,
            'subscription_ends_at' => '2027-09-01 00:00:00',
        ]);
    }

    // ── Staff ─────────────────────────────────────────────────────────────────

    private function createOwner(School $school): User
    {
        $u = User::create([
            'school_id'            => $school->id,
            'role'                 => 'school_owner',
            'name'                 => 'M. Abdelaziz Benkirane',
            'email'                => 'directeur@gs-averroes.ma',
            'phone'                => '+212 661 00 10 01',
            'password'             => Hash::make('Demo@12345'),
            'is_active'            => true,
            'base_salary'          => 9000.00,
            'salary_type'          => 'fixed',
            'salary_variable_rate' => null,
        ]);
        $u->assignRole('school_owner');
        return $u;
    }

    private function createAdmin(School $school): User
    {
        $u = User::create([
            'school_id'            => $school->id,
            'role'                 => 'admin',
            'name'                 => 'Mme Khadija Senhaji',
            'email'                => 'admin@gs-averroes.ma',
            'phone'                => '+212 661 00 10 02',
            'password'             => Hash::make('Demo@12345'),
            'is_active'            => true,
            'base_salary'          => 5000.00,
            'salary_type'          => 'fixed',
            'salary_variable_rate' => null,
        ]);
        $u->assignRole('admin');
        return $u;
    }

    private function createTeachers(School $school): array
    {
        // [name, email, phone, base_salary, description]
        $data = [
            // Primary class-teachers (0-5) — handle all subjects for their grade
            ['Mme Fatima Naciri',    'primaire1@gs-averroes.ma', '+212 661 10 10 01', 2800, 'Institutrice 1AP'],
            ['M. Hicham Berrada',    'primaire2@gs-averroes.ma', '+212 661 10 10 02', 2800, 'Instituteur 2AP'],
            ['Mme Salwa Rhali',      'primaire3@gs-averroes.ma', '+212 661 10 10 03', 2900, 'Institutrice 3AP'],
            ['M. Kamal Ouazzani',    'primaire4@gs-averroes.ma', '+212 661 10 10 04', 2900, 'Instituteur 4AP'],
            ['Mme Latifa Mansouri',  'primaire5@gs-averroes.ma', '+212 661 10 10 05', 3000, 'Institutrice 5AP'],
            ['M. Driss Filali',      'primaire6@gs-averroes.ma', '+212 661 10 10 06', 3000, 'Instituteur 6AP'],
            // Subject specialists (6-14)
            ['M. Youssef Alami',     'maths@gs-averroes.ma',    '+212 661 10 10 07', 4200, 'Maths'],
            ['Mme Fatima Benali',    'francais@gs-averroes.ma', '+212 661 10 10 08', 3800, 'Français'],
            ['M. Ahmed Tazi',        'arabe@gs-averroes.ma',    '+212 661 10 10 09', 3600, 'Arabe'],
            ['Mme Sara Moussaoui',   'pc@gs-averroes.ma',       '+212 661 10 10 10', 4500, 'Physique-Chimie'],
            ['M. Mohammed Cherkaoui','svt@gs-averroes.ma',      '+212 661 10 10 11', 3500, 'SVT & Sciences'],
            ['Mme Nadia Benjelloun', 'hg@gs-averroes.ma',       '+212 661 10 10 12', 3700, 'Histoire-Géo & Philo'],
            ['M. Karim Idrissi',     'eps@gs-averroes.ma',      '+212 661 10 10 13', 3200, 'EPS'],
            ['Mme Laila Tahiri',     'anglais@gs-averroes.ma',  '+212 661 10 10 14', 4000, 'Anglais'],
            ['M. Omar Ouazzani',     'info@gs-averroes.ma',     '+212 661 10 10 15', 3900, 'Informatique'],
        ];
        $teachers = [];
        foreach ($data as $d) {
            $t = User::create([
                'school_id'            => $school->id,
                'role'                 => 'teacher',
                'name'                 => $d[0],
                'email'                => $d[1],
                'phone'                => $d[2],
                'password'             => Hash::make('Demo@12345'),
                'is_active'            => true,
                'base_salary'          => $d[3],
                'salary_type'          => 'fixed',
                'salary_variable_rate' => null,
            ]);
            $t->assignRole('teacher');
            $teachers[] = $t;
        }
        return $teachers;
    }

    // ── Rooms (14) ────────────────────────────────────────────────────────────

    private function createRooms(School $school): array
    {
        $data = [
            // Primary homerooms (0-5)
            ['Salle 1AP', 'P101', 30, true],
            ['Salle 2AP', 'P102', 30, true],
            ['Salle 3AP', 'P103', 30, true],
            ['Salle 4AP', 'P104', 30, true],
            ['Salle 5AP', 'P105', 30, true],
            ['Salle 6AP', 'P106', 30, true],
            // Secondary homerooms (6-10)
            ['Salle S201',      'S201',  36, true],
            ['Salle S202',      'S202',  36, true],
            ['Salle S203',      'S203',  36, true],
            ['Salle S204',      'S204',  36, true],
            ['Salle S205',      'S205',  36, true],
            // Special rooms (11-13)
            ['Laboratoire',      'LAB',   24, true],
            ['Salle Informatique','INFO',  22, true],
            ['Terrain de Sport', 'SPORT', 80, true],
        ];
        $rooms = [];
        foreach ($data as $d) {
            $rooms[] = Room::create([
                'school_id'    => $school->id,
                'name'         => $d[0],
                'code'         => $d[1],
                'capacity'     => $d[2],
                'is_available' => $d[3],
            ]);
        }
        return $rooms;
    }

    // ── Grades (12) ───────────────────────────────────────────────────────────

    private function createGrades(School $school): array
    {
        $data = [
            // Cycle Primaire (1-6)
            ['1ère Année Primaire', 1,  '1AP — Cycle primaire (6-7 ans)'],
            ['2ème Année Primaire', 2,  '2AP — Cycle primaire (7-8 ans)'],
            ['3ème Année Primaire', 3,  '3AP — Cycle primaire (8-9 ans)'],
            ['4ème Année Primaire', 4,  '4AP — Cycle primaire (9-10 ans)'],
            ['5ème Année Primaire', 5,  '5AP — Cycle primaire (10-11 ans)'],
            ['6ème Année Primaire', 6,  '6AP — Cycle primaire (11-12 ans)'],
            // Cycle Collégial (7-9)
            ['1ère Année Collège',  7,  '1AC — Cycle collégial (12-13 ans)'],
            ['2ème Année Collège',  8,  '2AC — Cycle collégial (13-14 ans)'],
            ['3ème Année Collège',  9,  '3AC — Brevet des collèges (BTEM)'],
            // Cycle Lycéen Qualifiant (10-12)
            ['Tronc Commun',        10, 'TC — Première année cycle qualifiant'],
            ['1ère Baccalauréat',   11, '1BAC — Sciences & Maths ou Lettres'],
            ['2ème Baccalauréat',   12, '2BAC — Baccalauréat Sciences Physiques'],
        ];
        $grades = [];
        foreach ($data as $d) {
            $grades[] = Grade::create([
                'school_id'   => $school->id,
                'name'        => $d[0],
                'order'       => $d[1],
                'description' => $d[2],
            ]);
        }
        return $grades;
    }

    // ── Classrooms (12) ───────────────────────────────────────────────────────
    // [grade_idx, teacher_idx, room_idx, name, section, capacity]

    private function createClassrooms(School $school, array $grades, array $teachers, array $rooms): array
    {
        $data = [
            // Primary (0-5)
            [0,  0,  0,  '1AP-A', '1ère AP – Classe A', 28],
            [1,  1,  1,  '2AP-A', '2ème AP – Classe A', 28],
            [2,  2,  2,  '3AP-A', '3ème AP – Classe A', 28],
            [3,  3,  3,  '4AP-A', '4ème AP – Classe A', 28],
            [4,  4,  4,  '5AP-A', '5ème AP – Classe A', 28],
            [5,  5,  5,  '6AP-A', '6ème AP – Classe A', 28],
            // Middle (6-9)
            [6,  8,  6,  '1AC-A', '1ère AC – Section A', 35],
            [6,  7,  7,  '1AC-B', '1ère AC – Section B', 35],
            [7,  6,  8,  '2AC-A', '2ème AC – Section A', 35],
            [8,  9,  9,  '3AC-A', '3ème AC – Section A', 35],
            // High (10-11)
            [9,  11, 10, 'TC-A',    'Tronc Commun – Section A',          35],
            [10,  6,  8, '1BAC-SM', '1ère Bac – Sciences & Maths',       35],
            [11, 11,  9, '2BAC-SP', '2ème Bac – Sciences Physiques',     35],
        ];
        $classrooms = [];
        foreach ($data as $d) {
            $classrooms[] = Classroom::create([
                'school_id'     => $school->id,
                'grade_id'      => $grades[$d[0]]->id,
                'teacher_id'    => $teachers[$d[1]]->id,
                'room_id'       => $rooms[$d[2]]->id,
                'name'          => $d[3],
                'section'       => $d[4],
                'capacity'      => $d[5],
                'academic_year' => self::ACADEMIC_YEAR,
                'is_active'     => true,
            ]);
        }
        return $classrooms;
    }

    // ── Timetable ─────────────────────────────────────────────────────────────
    // Full timetable for 1AC-A (index 6) and TC-A (index 10).
    // All teacher and room conflicts verified — no overlap.
    // Days: 1=Lundi 2=Mardi 3=Mercredi 4=Jeudi 5=Vendredi 6=Samedi
    // Rooms: 6=S201(1AC-A home), 10=S205(TC-A home), 11=Lab, 12=Info, 13=Sport

    private function createTimetable(School $school, array $classes, array $teachers, array $rooms): void
    {
        [$tP1,$tP2,$tP3,$tP4,$tP5,$tP6,$tMaths,$tFr,$tAr,$tPC,$tSVT,$tHG,$tEPS,$tEn,$tInfo] = $teachers;
        $c1ACA = $classes[6];
        $cTCA  = $classes[10];
        [$rS201, , , , , , $rS201home, $rS202, $rS203, $rS204, $rS205, $rLab, $rInfo, $rSport] = $rooms;
        // Re-alias for clarity
        $rHome1AC = $rooms[6];  // S201
        $rHomeTC  = $rooms[10]; // S205
        $rLab     = $rooms[11];
        $rInfo    = $rooms[12];
        $rSport   = $rooms[13];

        // [classroom, teacher, room, subject, day, start, end]
        $slots = [
            // ── 1AC-A (home: S201) ────────────────────────────────────────────
            [$c1ACA, $tMaths, $rHome1AC, 'Mathématiques',                     1, '08:00:00', '10:00:00'],
            [$c1ACA, $tAr,    $rHome1AC, 'Langue Arabe',                      1, '10:00:00', '12:00:00'],
            [$c1ACA, $tFr,    $rHome1AC, 'Langue Française',                  2, '08:00:00', '10:00:00'],
            [$c1ACA, $tPC,    $rLab,     'Physique-Chimie',                   2, '10:00:00', '12:00:00'],
            [$c1ACA, $tHG,    $rHome1AC, 'Histoire-Géographie',               3, '08:00:00', '10:00:00'],
            [$c1ACA, $tEn,    $rHome1AC, 'Langue Anglaise',                   3, '10:00:00', '12:00:00'],
            [$c1ACA, $tSVT,   $rLab,     'Sciences de la Vie et de la Terre', 4, '08:00:00', '10:00:00'],
            [$c1ACA, $tAr,    $rHome1AC, 'Langue Arabe',                      4, '10:00:00', '12:00:00'],
            [$c1ACA, $tFr,    $rHome1AC, 'Langue Française',                  5, '08:00:00', '10:00:00'],
            [$c1ACA, $tMaths, $rHome1AC, 'Mathématiques',                     5, '10:00:00', '12:00:00'],
            [$c1ACA, $tInfo,  $rInfo,    'Informatique',                      6, '08:00:00', '10:00:00'],
            [$c1ACA, $tEPS,   $rSport,   'Éducation Physique et Sportive',    6, '10:00:00', '12:00:00'],

            // ── TC-A (home: S205) — afternoon/morning slots, no conflict with 1AC-A ──
            // Day 1 (Lundi)
            [$cTCA,  $tPC,    $rLab,     'Physique-Chimie',                   1, '14:00:00', '16:00:00'],
            [$cTCA,  $tMaths, $rHomeTC,  'Mathématiques',                     1, '16:00:00', '18:00:00'],
            // Day 2 (Mardi)
            [$cTCA,  $tMaths, $rHomeTC,  'Mathématiques',                     2, '14:00:00', '16:00:00'],
            [$cTCA,  $tFr,    $rHomeTC,  'Langue Française',                  2, '16:00:00', '18:00:00'],
            // Day 3 (Mercredi)
            [$cTCA,  $tAr,    $rHomeTC,  'Langue Arabe',                      3, '14:00:00', '16:00:00'],
            [$cTCA,  $tHG,    $rHomeTC,  'Histoire-Géographie',               3, '16:00:00', '18:00:00'],
            // Day 4 (Jeudi)
            [$cTCA,  $tPC,    $rLab,     'Physique-Chimie',                   4, '14:00:00', '16:00:00'],
            [$cTCA,  $tSVT,   $rLab,     'Sciences de la Vie et de la Terre', 4, '16:00:00', '18:00:00'],
            // Day 5 (Vendredi)
            [$cTCA,  $tEn,    $rHomeTC,  'Langue Anglaise',                   5, '14:00:00', '16:00:00'],
            [$cTCA,  $tHG,    $rHomeTC,  'Philosophie',                       5, '16:00:00', '18:00:00'],
            // Day 6 (Samedi)
            [$cTCA,  $tEPS,   $rSport,   'Éducation Physique et Sportive',    6, '14:00:00', '16:00:00'],
            [$cTCA,  $tInfo,  $rInfo,    'Informatique',                      6, '16:00:00', '18:00:00'],
        ];

        foreach ($slots as $s) {
            TimetableSlot::create([
                'school_id'    => $school->id,
                'classroom_id' => $s[0]->id,
                'teacher_id'   => $s[1]->id,
                'room_id'      => $s[2]->id,
                'subject'      => $s[3],
                'day_of_week'  => $s[4],
                'start_time'   => $s[5],
                'end_time'     => $s[6],
                'valid_from'   => null,
                'valid_to'     => null,
            ]);
        }
    }

    // ── Students ──────────────────────────────────────────────────────────────
    // [name, email, phone, dob, guardian_name, guardian_phone, address, classroom_idx]

    private function createStudents(School $school, array $classes): array
    {
        $data = [
            // ── 1AP-A (5 élèves) ─────────────────────────────────────────────
            ['Amira Benali',    'a.benali.p@gs-averroes.ma',  '+212661300101', '2018-03-10', 'M. Samir Benali',    '+212661300100', 'Gauthier, Casablanca',   0],
            ['Hamza Ouali',     'h.ouali.p@gs-averroes.ma',   '+212661300102', '2018-07-15', 'Mme Siham Ouali',    '+212661300110', 'Racine, Casablanca',     0],
            ['Lina Tahiri',     'l.tahiri.p@gs-averroes.ma',  '+212661300103', '2018-05-22', 'M. Rachid Tahiri',   '+212661300120', 'Bourgogne, Casablanca',  0],
            ['Adam Cherkaoui',  'a.cherk.p@gs-averroes.ma',   '+212661300104', '2018-01-30', 'Mme Naima Cherkaoui','+212661300130', 'Maârif, Casablanca',     0],
            ['Rania Filali',    'r.filali.p@gs-averroes.ma',  '+212661300105', '2018-09-04', 'M. Hassan Filali',   '+212661300140', 'Anfa, Casablanca',       0],
            // ── 2AP-A (5 élèves) ─────────────────────────────────────────────
            ['Yasmine Alami',   'y.alami.p@gs-averroes.ma',   '+212661300201', '2017-04-18', 'M. Karim Alami',     '+212661300200', 'Gauthier, Casablanca',   1],
            ['Rayane Berrada',  'r.berrada.p@gs-averroes.ma', '+212661300202', '2017-08-25', 'Mme Wafa Berrada',   '+212661300210', 'Racine, Casablanca',     1],
            ['Sana Lahlou',     's.lahlou.p@gs-averroes.ma',  '+212661300203', '2017-02-12', 'M. Driss Lahlou',    '+212661300220', 'Bourgogne, Casablanca',  1],
            ['Othmane Fassi',   'o.fassi.p@gs-averroes.ma',   '+212661300204', '2017-06-07', 'Mme Kenza Fassi',    '+212661300230', 'Anfa, Casablanca',       1],
            ['Douha Qabbaj',    'd.qabbaj.p@gs-averroes.ma',  '+212661300205', '2017-11-20', 'M. Youssef Qabbaj',  '+212661300240', 'Gauthier, Casablanca',   1],
            // ── 3AP-A (5 élèves) ─────────────────────────────────────────────
            ['Mehdi Mansouri',  'm.mansouri.p@gs-averroes.ma','+212661300301', '2016-03-08', 'Mme Loubna Mansouri','+212661300300', 'Racine, Casablanca',     2],
            ['Nour Ziani',      'n.ziani.p@gs-averroes.ma',   '+212661300302', '2016-09-14', 'M. Said Ziani',      '+212661300310', 'Bourgogne, Casablanca',  2],
            ['Ilias Rhali',     'i.rhali.p@gs-averroes.ma',   '+212661300303', '2016-06-27', 'Mme Fatima Rhali',   '+212661300320', 'Gauthier, Casablanca',   2],
            ['Sara Kettani',    's.kettani.p@gs-averroes.ma', '+212661300304', '2016-12-03', 'M. Anas Kettani',    '+212661300330', 'Anfa, Casablanca',       2],
            ['Badr Ouazzani',   'b.ouazzani.p@gs-averroes.ma','+212661300305', '2016-04-19', 'Mme Hind Ouazzani',  '+212661300340', 'Maârif, Casablanca',     2],
            // ── 4AP-A (5 élèves) ─────────────────────────────────────────────
            ['Imane Alaoui',    'i.alaoui.p@gs-averroes.ma',  '+212661300401', '2015-02-11', 'M. Nabil Alaoui',    '+212661300400', 'Gauthier, Casablanca',   3],
            ['Karim Idrissi',   'k.idrissi.p@gs-averroes.ma', '+212661300402', '2015-07-28', 'Mme Amina Idrissi',  '+212661300410', 'Racine, Casablanca',     3],
            ['Hajar Bennis',    'h.bennis.p@gs-averroes.ma',  '+212661300403', '2015-05-16', 'M. Tarik Bennis',    '+212661300420', 'Bourgogne, Casablanca',  3],
            ['Soufiane Tazi',   's.tazi.p@gs-averroes.ma',    '+212661300404', '2015-10-09', 'Mme Sanaa Tazi',     '+212661300430', 'Anfa, Casablanca',       3],
            ['Rima Senhadji',   'r.senhadji.p@gs-averroes.ma','+212661300405', '2015-01-23', 'M. Omar Senhadji',   '+212661300440', 'Gauthier, Casablanca',   3],
            // ── 5AP-A (4 élèves) ─────────────────────────────────────────────
            ['Bilal Moujahid',  'b.moujahid.p@gs-averroes.ma','+212661300501', '2014-03-15', 'M. Fouad Moujahid',  '+212661300500', 'Maârif, Casablanca',     4],
            ['Zineb Saadi',     'z.saadi.p@gs-averroes.ma',   '+212661300502', '2014-08-22', 'Mme Najat Saadi',    '+212661300510', 'Gauthier, Casablanca',   4],
            ['Anas Qasimi',     'a.qasimi.p@gs-averroes.ma',  '+212661300503', '2014-06-05', 'M. Hicham Qasimi',   '+212661300520', 'Racine, Casablanca',     4],
            ['Nadia Chraibi',   'n.chraibi.p@gs-averroes.ma', '+212661300504', '2014-11-17', 'Mme Fatima Chraibi', '+212661300530', 'Bourgogne, Casablanca',  4],
            // ── 6AP-A (4 élèves) ─────────────────────────────────────────────
            ['Walid Benkiran',  'w.benkiran.p@gs-averroes.ma','+212661300601', '2013-04-10', 'M. Said Benkiran',   '+212661300600', 'Anfa, Casablanca',       5],
            ['Meriem Laaziz',   'm.laaziz.p@gs-averroes.ma',  '+212661300602', '2013-07-19', 'Mme Houda Laaziz',   '+212661300610', 'Gauthier, Casablanca',   5],
            ['Amine Benabdallah','a.benabda.p@gs-averroes.ma','+212661300603', '2013-02-28', 'M. Khalid Benabdallah','+212661300620','Maârif, Casablanca',   5],
            ['Hind Slimani',    'h.slimani.p@gs-averroes.ma', '+212661300604', '2013-09-03', 'Mme Salwa Slimani',  '+212661300630', 'Racine, Casablanca',     5],
            // ── 1AC-A (8 élèves) ─────────────────────────────────────────────
            ['Youssef Alaoui',    'y.alaoui.s@gs-averroes.ma',   '+212661400101', '2013-03-15', 'M. Rachid Alaoui',     '+212661400100', 'Hay Hassani, Casa',     6],
            ['Mohammed Berrada',  'm.berrada.s@gs-averroes.ma',  '+212661400102', '2013-07-22', 'Mme Naima Berrada',    '+212661400110', 'Maârif, Casa',          6],
            ['Fatima Ziani',      'f.ziani.s@gs-averroes.ma',    '+212661400103', '2012-11-05', 'M. Khalid Ziani',      '+212661400120', 'Oulfa, Casa',           6],
            ['Ahmed Lamrani',     'a.lamrani.s@gs-averroes.ma',  '+212661400104', '2013-01-18', 'Mme Saida Lamrani',    '+212661400130', 'Ain Chock, Casa',       6],
            ['Sara Guerraoui',    's.guerraoui.s@gs-averroes.ma','+212661400105', '2013-05-30', 'M. Jamal Guerraoui',   '+212661400140', 'Bernoussi, Casa',       6],
            ['Omar Sebti',        'o.sebti.s@gs-averroes.ma',    '+212661400106', '2012-09-12', 'Mme Khadija Sebti',    '+212661400150', "Ben M'Sick, Casa",      6],
            ['Nada Benhima',      'n.benhima.s@gs-averroes.ma',  '+212661400107', '2013-02-25', 'M. Hassan Benhima',    '+212661400160', 'Derb Sultan, Casa',     6],
            ['Khalid Mekki',      'k.mekki.s@gs-averroes.ma',   '+212661400108', '2012-12-08', 'Mme Zineb Mekki',      '+212661400170', 'Anfa, Casa',            6],
            // ── 1AC-B (6 élèves) ─────────────────────────────────────────────
            ['Zineb Boutaleb',    'z.boutaleb.s@gs-averroes.ma', '+212661400201', '2013-04-14', 'M. Samir Boutaleb',    '+212661400200', 'Anfa, Casa',            7],
            ['Karim Lazrak',      'k.lazrak.s@gs-averroes.ma',   '+212661400202', '2012-08-19', 'Mme Leila Lazrak',     '+212661400210', 'Roches Noires, Casa',   7],
            ['Imane Kettani',     'i.kettani.s@gs-averroes.ma',  '+212661400203', '2013-06-03', 'M. Hassan Kettani',    '+212661400220', 'Ain Sebaa, Casa',       7],
            ['Soufiane Hafidi',   's.hafidi.s@gs-averroes.ma',   '+212661400204', '2013-10-27', 'Mme Amina Hafidi',     '+212661400230', 'Sidi Moumen, Casa',     7],
            ['Houda Benkirane',   'h.benkirane.s@gs-averroes.ma','+212661400205', '2012-07-16', 'M. Rachid Benkirane',  '+212661400240', 'Bouskoura',             7],
            ['Tariq Laaroussi',   't.laaroussi.s@gs-averroes.ma','+212661400206', '2013-03-08', 'Mme Nadia Laaroussi',  '+212661400250', 'Mohammedia',            7],
            // ── 2AC-A (7 élèves) ─────────────────────────────────────────────
            ['Ilyas Bensouda',    'i.bensouda.s@gs-averroes.ma', '+212661400301', '2012-02-11', 'M. Rachid Bensouda',   '+212661400300', 'Maârif, Casa',          8],
            ['Hafsa Sabiri',      'h.sabiri.s@gs-averroes.ma',   '+212661400302', '2011-09-25', 'Mme Siham Sabiri',     '+212661400310', 'Ain Diab, Casa',        8],
            ['Ismail Rahhali',    'i.rahhali.s@gs-averroes.ma',  '+212661400303', '2012-05-17', 'M. Omar Rahhali',      '+212661400320', 'Oasis, Casa',           8],
            ['Ikram Benkirane2',  'i.benkir.s@gs-averroes.ma',   '+212661400304', '2011-12-30', 'Mme Fatima Benkirane', '+212661400330', 'Bourgogne, Casa',       8],
            ['Zakaria Guerraoui2','z.guerr.s@gs-averroes.ma',    '+212661400305', '2012-03-08', 'M. Youssef Guerraoui', '+212661400340', 'Belvédère, Casa',       8],
            ['Salma Filali2',     's.filali2.s@gs-averroes.ma',  '+212661400306', '2011-07-14', 'Mme Karima Filali',    '+212661400350', 'Anfa, Casa',            8],
            ['Bilal Baakil',      'b.baakil.s@gs-averroes.ma',   '+212661400307', '2012-10-05', 'M. Tariq Baakil',      '+212661400360', 'Bouskoura',             8],
            // ── 3AC-A (6 élèves) ─────────────────────────────────────────────
            ['Rim El Idrissi',    'r.elidrissi.s@gs-averroes.ma','+212661400401', '2010-08-23', 'M. Said El Idrissi',   '+212661400400', 'Maârif, Casa',          9],
            ['Adam Bennis2',      'a.bennis2.s@gs-averroes.ma',  '+212661400402', '2011-01-06', 'Mme Hind Bennis',      '+212661400410', 'Oulfa, Casa',           9],
            ['Wafa Tlemcani',     'w.tlemcani.s@gs-averroes.ma', '+212661400403', '2010-06-18', 'M. Nasser Tlemcani',   '+212661400420', 'Hay Mohammadi, Casa',   9],
            ['Taha Kharbouch',    't.kharbouch.s@gs-averroes.ma','+212661400404', '2011-03-27', 'Mme Fatma Kharbouch',  '+212661400430', 'Sidi Belyout, Casa',    9],
            ['Chaimaa Sqalli',    'c.sqalli.s@gs-averroes.ma',   '+212661400405', '2010-11-09', 'M. Karim Sqalli',      '+212661400440', 'Anfa, Casa',            9],
            ['Saad Berrada2',     's.berrada2.s@gs-averroes.ma', '+212661400406', '2011-05-14', 'Mme Latifa Berrada',   '+212661400450', 'Ain Chock, Casa',       9],
            // ── TC-A (6 élèves) ──────────────────────────────────────────────
            ['Walid El Amrani',   'w.elamrani.s@gs-averroes.ma', '+212661400501', '2010-02-14', 'M. Nabil El Amrani',   '+212661400500', 'Maârif, Casa',         10],
            ['Btissam Slaoui',    'b.slaoui.s@gs-averroes.ma',   '+212661400502', '2009-07-30', 'Mme Sanaa Slaoui',     '+212661400510', 'Anfa, Casa',           10],
            ['Anass Belghiti',    'a.belghiti.s@gs-averroes.ma', '+212661400503', '2010-04-22', 'M. Fouad Belghiti',    '+212661400520', 'Bourgogne, Casa',      10],
            ['Loubna Sqalli2',    'l.sqalli2.s@gs-averroes.ma',  '+212661400504', '2009-11-07', 'M. Karim Sqalli',      '+212661400530', 'Belvédère, Casa',      10],
            ['Manal El Khatib',   'm.elkhatib.s@gs-averroes.ma', '+212661400505', '2010-08-16', 'Mme Najat El Khatib',  '+212661400540', 'Hay Hassani, Casa',    10],
            ['Nabil Chikhi',      'n.chikhi.s@gs-averroes.ma',   '+212661400506', '2009-12-03', 'M. Hafid Chikhi',      '+212661400550', 'Mohammedia',           10],
            // ── 1BAC-SM (5 élèves) ───────────────────────────────────────────
            ['Yasmine Tahiri2',   'y.tahiri2.s@gs-averroes.ma',  '+212661400601', '2009-03-18', 'M. Larbi Tahiri',      '+212661400600', 'Maârif, Casa',         11],
            ['Mehdi Ouazzani2',   'm.ouazz2.s@gs-averroes.ma',   '+212661400602', '2008-10-05', 'Mme Asmaa Ouazzani',   '+212661400610', 'Ain Diab, Casa',       11],
            ['Hicham El Ouafi',   'h.elouafi.s@gs-averroes.ma',  '+212661400603', '2009-06-25', 'M. Driss El Ouafi',    '+212661400620', 'Oasis, Casa',          11],
            ['Siham Kettani2',    's.kett2.s@gs-averroes.ma',    '+212661400604', '2008-01-12', 'Mme Fatima Kettani',   '+212661400630', 'Oulfa, Casa',          11],
            ['Tarik Benali',      't.benali.s@gs-averroes.ma',   '+212661400605', '2009-08-09', 'M. Youssef Benali',    '+212661400640', 'Bourgogne, Casa',      11],
            // ── 2BAC-SP (4 élèves) ───────────────────────────────────────────
            ['Sanaa El Fassi',    's.elfassi.s@gs-averroes.ma',  '+212661400701', '2007-09-14', 'M. Bachir El Fassi',   '+212661400700', 'Maârif, Casa',         12],
            ['Khalid Lahlou2',    'k.lahlou2.s@gs-averroes.ma',  '+212661400702', '2008-02-28', 'Mme Milouda Lahlou',   '+212661400710', 'Anfa, Casa',           12],
            ['Meryem Senhaji',    'm.senhaji.s@gs-averroes.ma',  '+212661400703', '2007-07-07', 'M. Azzeddine Senhaji', '+212661400720', 'Bouskoura',            12],
            ['Rachid Filali2',    'r.filali2.s@gs-averroes.ma',  '+212661400704', '2008-04-15', 'Mme Naima Filali',     '+212661400730', 'Oasis, Casa',          12],
        ];

        $students = [];
        $counter  = 1;

        foreach ($data as $d) {
            [$name, $email, $phone, $dob, $guardName, $guardPhone, $address, $classroomIdx] = $d;

            $user = User::create([
                'school_id' => $school->id,
                'role'      => 'student',
                'name'      => $name,
                'email'     => $email,
                'phone'     => $phone,
                'password'  => Hash::make('Demo@12345'),
                'is_active' => true,
            ]);
            $user->assignRole('student');

            $profile = StudentProfile::create([
                'user_id'           => $user->id,
                'school_id'         => $school->id,
                'enrollment_number' => 'AVR' . str_pad($counter++, 4, '0', STR_PAD_LEFT),
                'date_of_birth'     => $dob,
                'guardian_name'     => $guardName,
                'guardian_phone'    => $guardPhone,
                'guardian_email'    => null,
                'address'           => $address,
                'status'            => 'active',
            ]);

            $classroom = $classes[$classroomIdx];
            DB::table('student_classroom')->insert([
                'student_id'         => $user->id,
                'student_profile_id' => $profile->id,
                'classroom_id'       => $classroom->id,
                'enrolled_at'        => self::ENROLL_DATE,
                'left_at'            => null,
                'status'             => 'active',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $students[] = ['user' => $user, 'profile' => $profile, 'classroom' => $classroom];
        }

        return $students;
    }

    // ── Payments ──────────────────────────────────────────────────────────────
    // Fees: Primary 500 MAD | Collège 700 MAD | TC 800 MAD | 1BAC 900 MAD | 2BAC 950 MAD

    private function createPayments(School $school, array $students, array $classes, User $admin): void
    {
        // Map classroom index → monthly fee
        $feeByClassIdx = [
            0 => 500, 1 => 500, 2 => 500, 3 => 500, 4 => 500, 5 => 500,  // primary
            6 => 700, 7 => 700, 8 => 700, 9 => 700,                        // collège
            10 => 800, 11 => 900, 12 => 950,                               // lycée
        ];

        // Build classroom_id → fee lookup
        $feeMap = [];
        foreach ($feeByClassIdx as $idx => $fee) {
            $feeMap[$classes[$idx]->id] = $fee;
        }

        foreach ($students as $idx => $s) {
            $fee = $feeMap[$s['classroom']->id] ?? 700;

            foreach (self::PAYMENT_MONTHS as $mIdx => [$year, $month]) {
                [$status, $paidAt] = $this->resolvePaymentStatus($idx, $mIdx);

                Payment::create([
                    'school_id'          => $school->id,
                    'student_id'         => $s['user']->id,
                    'student_profile_id' => $s['profile']->id,
                    'recorded_by'        => $admin->id,
                    'year'               => $year,
                    'month'              => $month,
                    'amount'             => $fee,
                    'status'             => $status,
                    'notes'              => $status === 'partial' ? 'Paiement partiel — solde à régler' : null,
                    'paid_at'            => $paidAt,
                ]);
            }
        }
    }

    private function resolvePaymentStatus(int $sIdx, int $mIdx): array
    {
        $hash = (($sIdx * 31) + ($mIdx * 17) + ($sIdx ^ $mIdx)) % 100;

        if ($mIdx < 4) {
            $status = $hash < 82 ? 'paid' : ($hash < 92 ? 'partial' : ($hash < 96 ? 'waived' : 'unpaid'));
        } elseif ($mIdx < 7) {
            $status = $hash < 74 ? 'paid' : ($hash < 87 ? 'partial' : 'unpaid');
        } elseif ($mIdx < 9) {
            $status = $hash < 62 ? 'paid' : ($hash < 80 ? 'partial' : 'unpaid');
        } else {
            $status = $hash < 48 ? 'paid' : ($hash < 68 ? 'partial' : 'unpaid');
        }

        $paidAt = null;
        if ($status === 'paid' || $status === 'partial') {
            $day    = 1 + (($sIdx * 3 + $mIdx * 7) % 20);
            [$year, $month] = self::PAYMENT_MONTHS[$mIdx];
            $paidAt = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return [$status, $paidAt];
    }

    // ── Files ─────────────────────────────────────────────────────────────────

    private function createFiles(School $school, User $admin, array $teachers, array $grades, array $classes): void
    {
        [$tP1,$tP2,$tP3,$tP4,$tP5,$tP6,$tMaths,$tFr,$tAr] = $teachers;
        [$g1AP,,,$g4AP,,$g6AP,$g1AC,,,$g3AC,$gTC] = $grades;
        $c1ACA = $classes[6];
        $cTCA  = $classes[10];

        $files = [
            [$admin,  null,   null,  'school', 'Règlement Intérieur 2025-2026',             'Règlement du groupe scolaire',             'pdf',  'application/pdf',   124800],
            [$admin,  null,   null,  'school', 'Calendrier Scolaire 2025-2026',             'Dates vacances et examens',                'pdf',  'application/pdf',    89200],
            [$admin,  null,   null,  'school', 'Projet Pédagogique Annuel',                 'Objectifs et axes pédagogiques',           'pdf',  'application/pdf',   245000],
            [$tP1,    $g1AP,  null,  'grade',  'Programme 1AP — Cycle Primaire',            'Programme officiel 1ère année primaire',   'pdf',  'application/pdf',   180000],
            [$tMaths, $g1AC,  null,  'grade',  'Programme Maths 1AC',                      'Programme officiel mathématiques 1AC',     'pdf',  'application/pdf',   210000],
            [$tFr,    $g3AC,  null,  'grade',  'Liste des Textes Français 3AC',            'Textes officiels programme 3AC',           'docx', 'application/msword',  45000],
            [$tMaths, null,  $c1ACA,'class',   'Exercices Maths — Séquence 1 — 1AC-A',     'Exercices de consolidation chapitre 1',    'pdf',  'application/pdf',    95000],
            [$tFr,    null,  $c1ACA,'class',   'Contrôle Français Oct 2025 — 1AC-A',       'Sujet contrôle continu octobre',           'pdf',  'application/pdf',    42000],
            [$tMaths, null,  $cTCA, 'class',   'Exercices Maths Tronc Commun — Séq. 2',    'Consolidation fonctions et suites',        'pdf',  'application/pdf',   128000],
        ];

        foreach ($files as [$uploader, $grade, $classroom, $visibility, $title, $desc, $ext, $mime, $size]) {
            File::create([
                'school_id'    => $school->id,
                'uploaded_by'  => $uploader->id,
                'grade_id'     => $grade?->id,
                'classroom_id' => $classroom?->id,
                'title'        => $title,
                'description'  => $desc,
                'visibility'   => $visibility,
                'disk'         => 'local',
                'path'         => 'school_files/' . $school->id . '/demo/' . str_replace(' ', '_', $title) . '.' . $ext,
                'mime_type'    => $mime,
                'file_type'    => $ext,
                'size_bytes'   => $size,
            ]);
        }
    }

    // ── Attendance ────────────────────────────────────────────────────────────

    private function createAttendance(School $school, array $classes, array $students): void
    {
        $byClass = [];
        foreach ($students as $s) {
            $byClass[$s['classroom']->id][] = $s['profile'];
        }

        foreach ($classes as $classroom) {
            $profiles = $byClass[$classroom->id] ?? [];
            if (empty($profiles)) continue;

            for ($weekBack = 5; $weekBack >= 0; $weekBack--) {
                for ($dow = 1; $dow <= 5; $dow++) {
                    $date = Carbon::now()
                        ->subWeeks($weekBack)
                        ->startOfWeek(Carbon::MONDAY)
                        ->addDays($dow - 1);

                    if ($date->isFuture()) continue;

                    foreach ($profiles as $profile) {
                        $rand   = rand(1, 100);
                        $status = $rand <= 85 ? 'present' : ($rand <= 93 ? 'absent' : ($rand <= 97 ? 'late' : 'excused'));

                        Attendance::firstOrCreate(
                            [
                                'school_id'          => $school->id,
                                'class_type'         => 'classroom',
                                'classroom_id'       => $classroom->id,
                                'student_profile_id' => $profile->id,
                                'date'               => $date->toDateString(),
                            ],
                            ['course_class_id' => null, 'status' => $status, 'notes' => null]
                        );
                    }
                }
            }
        }
    }

    // ── Payroll + Expenses ────────────────────────────────────────────────────

    private function createExpenses(School $school, User $owner, User $admin, array $teachers): void
    {
        $now             = Carbon::now();
        $schoolYearStart = Carbon::create($now->year - 1, 9, 1)->startOfMonth();

        $allMonths = [];
        $cursor    = $schoolYearStart->copy();
        while ($cursor->lte($now)) {
            $allMonths[] = [$cursor->month, $cursor->year, $cursor->copy()];
            $cursor->addMonth();
        }

        $skipPayroll = [[$now->year, 5], [$now->year, 6]];
        $payrollMonths = array_filter($allMonths, function ($m) use ($skipPayroll) {
            foreach ($skipPayroll as [$sy, $sm]) {
                if ($m[1] === $sy && $m[0] === $sm) return false;
            }
            return true;
        });

        $personSalaries = array_merge(
            [[$owner, 9000.00], [$admin, 5000.00]],
            array_map(fn($t) => [$t, (float)$t->base_salary], $teachers)
        );

        foreach ($payrollMonths as [$month, $year, $monthDate]) {
            $paidAt = $monthDate->copy()->addDays(27);
            foreach ($personSalaries as [$person, $salary]) {
                PayrollEntry::create([
                    'school_id' => $school->id, 'user_id' => $person->id,
                    'month' => $month, 'year' => $year, 'type' => 'salary',
                    'base_amount' => $salary, 'variable_amount' => 0, 'total_amount' => $salary,
                    'description' => 'Salaire ' . $monthDate->format('m/Y'),
                    'status' => 'paid', 'paid_at' => $paidAt, 'created_by' => $admin->id,
                ]);
            }
            // Advances for 3 teachers (every 3 months)
            foreach (array_slice($teachers, 6, 3) as $i => $teacher) {
                if (($month + $i) % 3 === 0) {
                    PayrollEntry::create([
                        'school_id' => $school->id, 'user_id' => $teacher->id,
                        'month' => $month, 'year' => $year, 'type' => 'advance',
                        'base_amount' => 1200, 'variable_amount' => 0, 'total_amount' => 1200,
                        'description' => 'Avance sur salaire', 'status' => 'paid',
                        'paid_at' => $monthDate->copy()->addDays(10), 'created_by' => $admin->id,
                    ]);
                }
            }
            // Year-end bonuses
            if (in_array($month, [12, 3])) {
                foreach ([$admin, $owner] as $recipient) {
                    PayrollEntry::create([
                        'school_id' => $school->id, 'user_id' => $recipient->id,
                        'month' => $month, 'year' => $year, 'type' => 'bonus',
                        'base_amount' => 2500, 'variable_amount' => 0, 'total_amount' => 2500,
                        'description' => $month === 12 ? "Prime fin d'année" : 'Prime mi-année',
                        'status' => 'paid', 'paid_at' => $monthDate->copy()->addDays(20),
                        'created_by' => $owner->id,
                    ]);
                }
            }
        }

        $miscDefs = [
            ['transport',   'Remboursement frais déplacement — %s', [80,  350]],
            ['supplies',    'Fournitures bureau et pédagogiques',    [100, 600]],
            ['equipment',   'Maintenance vidéoprojecteur / tableau', [300, 1000]],
            ['maintenance', 'Entretien locaux scolaires',            [200, 700]],
            ['other',       'Frais téléphoniques et internet',       [80,  250]],
        ];
        $staff = array_merge([$owner, $admin], $teachers);
        foreach ($allMonths as [$month, $year, $monthDate]) {
            $count = rand(4, 8);
            for ($i = 0; $i < $count; $i++) {
                [$cat, $descTpl, $range] = $miscDefs[array_rand($miscDefs)];
                $person  = $staff[array_rand($staff)];
                $expDate = $monthDate->copy()->addDays(rand(1, 25))->toDateString();
                $isPast  = $monthDate->lt($now->copy()->startOfMonth());
                $status  = $isPast ? 'paid' : (['pending', 'approved'][rand(0, 1)]);
                StaffExpense::create([
                    'school_id' => $school->id, 'user_id' => $person->id, 'category' => $cat,
                    'description' => sprintf($descTpl, $person->name),
                    'amount' => rand($range[0], $range[1]),
                    'expense_date' => $expDate, 'status' => $status, 'notes' => null,
                ]);
            }
        }
    }

    // ── Activity Logs ─────────────────────────────────────────────────────────

    private function createActivityLogs(School $school, User $owner, User $admin, array $teachers): void
    {
        $now = now();
        $logs = [
            [$owner,   'school.setup',      'Groupe Scolaire Averroès — compte créé pour l\'année 2025-2026.',       $now->copy()->subDays(65)],
            [$admin,   'users.imported',    '15 enseignants ajoutés (6 instituteurs primaire + 9 professeurs).',      $now->copy()->subDays(63)],
            [$admin,   'grades.created',    '12 niveaux configurés — cycle primaire, collégial et lycéen.',           $now->copy()->subDays(62)],
            [$admin,   'rooms.created',     '14 salles enregistrées (primaire, secondaire, labo, info, sport).',      $now->copy()->subDays(61)],
            [$admin,   'classrooms.created','12 classes créées pour l\'année 2025-2026.',                             $now->copy()->subDays(60)],
            [$admin,   'timetable.published','Emploi du temps publié pour 1AC-A et TC-A.',                            $now->copy()->subDays(59)],
            [$admin,   'students.imported', '63 dossiers élèves importés et inscrits.',                               $now->copy()->subDays(57)],
            [$owner,   'user.login',        'Connexion du directeur M. Abdelaziz Benkirane.',                         $now->copy()->subDays(30)],
            [$admin,   'payments.recorded', 'Paiements septembre 2025 enregistrés pour toutes les classes.',          $now->copy()->subDays(28)],
            [$admin,   'payments.recorded', 'Paiements octobre et novembre 2025 enregistrés.',                        $now->copy()->subDays(15)],
            [$teachers[6], 'file.uploaded', 'Exercices Maths 1AC-A — Séquence 1 déposés.',                           $now->copy()->subDays(10)],
            [$admin,   'user.login',        'Connexion de Mme Khadija Senhaji (administration).',                     $now->copy()->subDays(1)],
        ];
        foreach ($logs as [$user, $action, $description, $createdAt]) {
            ActivityLog::create([
                'school_id'   => $school->id,
                'user_id'     => $user->id,
                'action'      => $action,
                'description' => $description,
                'ip_address'  => '197.230.' . rand(1, 254) . '.' . rand(1, 254),
                'user_agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'metadata'    => null,
                'created_at'  => $createdAt,
            ]);
        }
    }
}
