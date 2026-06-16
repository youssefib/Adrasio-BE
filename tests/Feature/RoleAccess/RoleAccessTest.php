<?php

namespace Tests\Feature\RoleAccess;

use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    public function test_unauthenticated_user_gets_401(): void
    {
        $this->getJson('/api/v1/school/grades')->assertStatus(401);
    }

    public function test_school_owner_can_create_grade(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $owner  = $this->createUser($school, 'school_owner');

        $this->actingAsUser($owner)
             ->postJson('/api/v1/school/grades', ['name' => 'Grade 1', 'order' => 1])
             ->assertStatus(201);
    }

    public function test_admin_can_create_grade(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $admin  = $this->createUser($school, 'admin');

        $this->actingAsUser($admin)
             ->postJson('/api/v1/school/grades', ['name' => 'Grade 2', 'order' => 2])
             ->assertStatus(201);
    }

    public function test_teacher_cannot_create_grade(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $teacher = $this->createUser($school, 'teacher');

        $this->actingAsUser($teacher)
             ->postJson('/api/v1/school/grades', ['name' => 'Grade 3', 'order' => 3])
             ->assertStatus(403);
    }

    public function test_student_cannot_create_grade(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school  = $this->createSchool();
        $student = $this->createUser($school, 'student');

        $this->actingAsUser($student)
             ->postJson('/api/v1/school/grades', ['name' => 'Grade 4', 'order' => 4])
             ->assertStatus(403);
    }

    public function test_system_admin_cannot_access_school_routes_without_header(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $sysAdmin = $this->createSystemAdmin();

        // System admin routes don't require school context
        $this->actingAsUser($sysAdmin)
             ->getJson('/api/v1/system/stats')
             ->assertOk();
    }

    public function test_regular_user_cannot_access_system_routes(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $admin  = $this->createUser($school, 'admin');

        $this->actingAsUser($admin)
             ->getJson('/api/v1/system/stats')
             ->assertStatus(403);
    }

    public function test_admin_cannot_create_system_admin_user(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $admin  = $this->createUser($school, 'admin');

        $this->actingAsUser($admin)
             ->postJson('/api/v1/school/users', [
                 'name'     => 'Hacker',
                 'email'    => 'hack@test.com',
                 'password' => 'password',
                 'role'     => 'system_admin', // not in allowed roles
             ])
             ->assertStatus(422);
    }
}
