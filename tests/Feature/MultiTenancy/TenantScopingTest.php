<?php

namespace Tests\Feature\MultiTenancy;

use Tests\TestCase;

class TenantScopingTest extends TestCase
{
    public function test_admin_cannot_see_grades_from_another_school(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $schoolA = $this->createSchool(['name' => 'School A', 'email' => 'a@a.com']);
        $schoolB = $this->createSchool(['name' => 'School B', 'email' => 'b@b.com']);

        $adminA = $this->createUser($schoolA, 'admin');
        $adminB = $this->createUser($schoolB, 'admin');

        // Create a grade in school B
        $this->actingAsUser($adminB)
             ->postJson('/api/v1/school/grades', ['name' => 'Grade B1', 'order' => 1])
             ->assertStatus(201);

        // Admin A should see zero grades (none in their school)
        $response = $this->actingAsUser($adminA)
                         ->getJson('/api/v1/school/grades')
                         ->assertOk();

        $this->assertCount(0, $response->json());
    }

    public function test_admin_cannot_manage_users_from_another_school(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $schoolA = $this->createSchool(['name' => 'School A', 'email' => 'a@a.com']);
        $schoolB = $this->createSchool(['name' => 'School B', 'email' => 'b@b.com']);

        $adminA  = $this->createUser($schoolA, 'admin');
        $teacher = $this->createUser($schoolB, 'teacher');

        // Admin A tries to view a user from School B
        $this->actingAsUser($adminA)
             ->getJson("/api/v1/school/users/{$teacher->id}")
             ->assertStatus(403);
    }

    public function test_system_admin_can_see_all_schools(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $this->createSchool(['name' => 'School A', 'email' => 'a@a.com']);
        $this->createSchool(['name' => 'School B', 'email' => 'b@b.com']);

        $sysAdmin = $this->createSystemAdmin();

        $response = $this->actingAsUser($sysAdmin)
                         ->getJson('/api/v1/system/schools')
                         ->assertOk();

        $this->assertGreaterThanOrEqual(2, $response->json('total'));
    }

    public function test_teacher_cannot_access_admin_routes(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $teacher = $this->createUser($school, 'teacher');

        $this->actingAsUser($teacher)
             ->getJson('/api/v1/school/users')
             ->assertStatus(403);
    }

    public function test_student_cannot_access_files_outside_their_classes(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $student = $this->createUser($school, 'student');

        // Student has no enrolled classes, so file list should be empty
        $response = $this->actingAsUser($student)
                         ->getJson('/api/v1/school/files')
                         ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }
}
