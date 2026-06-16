<?php

namespace Tests\Feature\Enrollment;

use App\Models\Grade;
use App\Models\StudentProfile;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    public function test_creating_student_user_auto_creates_student_profile(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $admin  = $this->createUser($school, 'admin');

        $response = $this->actingAsUser($admin)
                         ->postJson('/api/v1/school/users', [
                             'name'              => 'Jane Student',
                             'email'             => 'jane@test.com',
                             'password'          => 'password',
                             'role'              => 'student',
                             'enrollment_number' => 'S001',
                         ])
                         ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'jane@test.com']);
        $this->assertDatabaseHas('student_profiles', [
            'school_id'         => $school->id,
            'enrollment_number' => 'S001',
        ]);
    }

    public function test_student_can_be_enrolled_in_classroom(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $admin   = $this->createUser($school, 'admin');
        $teacher = $this->createUser($school, 'teacher');
        $student = $this->createUser($school, 'student');

        // Create grade and classroom
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'order' => 1]);

        $classRes = $this->actingAsUser($admin)
                         ->postJson('/api/v1/school/classes', [
                             'grade_id'      => $grade->id,
                             'name'          => '1A',
                             'academic_year' => '2025-2026',
                             'teacher_id'    => $teacher->id,
                         ])
                         ->assertStatus(201);

        $classroomId = $classRes->json('id');

        // Enroll student
        $this->actingAsUser($admin)
             ->postJson("/api/v1/school/classes/{$classroomId}/enroll", [
                 'student_id'  => $student->id,
                 'enrolled_at' => '2025-09-01',
             ])
             ->assertOk();

        $this->assertDatabaseHas('student_classroom', [
            'classroom_id' => $classroomId,
            'student_id'   => $student->id,
        ]);
    }

    public function test_student_sees_their_enrolled_classes_timetable(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $admin   = $this->createUser($school, 'admin');
        $teacher = $this->createUser($school, 'teacher');
        $student = $this->createUser($school, 'student');

        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'order' => 1]);

        $classRes = $this->actingAsUser($admin)
                         ->postJson('/api/v1/school/classes', [
                             'grade_id'      => $grade->id,
                             'name'          => '1A',
                             'academic_year' => '2025-2026',
                             'teacher_id'    => $teacher->id,
                         ]);
        $classroomId = $classRes->json('id');

        // Enroll student
        $this->actingAsUser($admin)
             ->postJson("/api/v1/school/classes/{$classroomId}/enroll", [
                 'student_id'  => $student->id,
                 'enrolled_at' => '2025-09-01',
             ]);

        // Add a timetable slot
        $this->actingAsUser($admin)
             ->postJson('/api/v1/school/timetable', [
                 'classroom_id' => $classroomId,
                 'teacher_id'   => $teacher->id,
                 'subject'      => 'Mathematics',
                 'day_of_week'  => 1,
                 'start_time'   => '08:00',
                 'end_time'     => '09:00',
             ])
             ->assertStatus(201);

        // Student views their timetable
        $response = $this->actingAsUser($student)
                         ->getJson('/api/v1/school/my-timetable')
                         ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertEquals('Mathematics', $response->json('0.subject'));
    }

    public function test_enrollment_is_unique_per_student_classroom(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $admin   = $this->createUser($school, 'admin');
        $student = $this->createUser($school, 'student');
        $teacher = $this->createUser($school, 'teacher');

        $grade = Grade::create(['school_id' => $school->id, 'name' => 'Grade 1', 'order' => 1]);

        $classRes = $this->actingAsUser($admin)
                         ->postJson('/api/v1/school/classes', [
                             'grade_id'      => $grade->id,
                             'name'          => '1B',
                             'academic_year' => '2025-2026',
                             'teacher_id'    => $teacher->id,
                         ]);
        $classroomId = $classRes->json('id');

        $payload = ['student_id' => $student->id, 'enrolled_at' => '2025-09-01'];

        $this->actingAsUser($admin)->postJson("/api/v1/school/classes/{$classroomId}/enroll", $payload)->assertOk();

        // Second enroll should be idempotent (syncWithoutDetaching), not error
        $this->actingAsUser($admin)->postJson("/api/v1/school/classes/{$classroomId}/enroll", $payload)->assertOk();

        $this->assertDatabaseCount('student_classroom', 1);
    }
}
