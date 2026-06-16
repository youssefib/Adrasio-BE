<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_school_can_register(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $response = $this->postJson('/api/v1/auth/register-school', [
            'school_name'          => 'Demo School',
            'school_email'         => 'demo@school.com',
            'owner_name'           => 'John Owner',
            'owner_email'          => 'john@school.com',
            'password'             => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['user', 'school', 'token']);

        $this->assertDatabaseHas('schools', ['email' => 'demo@school.com']);
        $this->assertDatabaseHas('users', ['email' => 'john@school.com']);
    }

    public function test_user_can_login(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'teacher');

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
                 ->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'teacher');

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'teacher', ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_login_fails_for_suspended_school(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool(['status' => 'suspended']);
        $user   = $this->createUser($school, 'teacher');

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_user_can_logout(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'teacher');

        $this->actingAsUser($user)
             ->postJson('/api/v1/auth/logout')
             ->assertOk();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'admin');

        $this->actingAsUser($user)
             ->getJson('/api/v1/auth/me')
             ->assertOk()
             ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_forgot_password_accepts_valid_email(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $school = $this->createSchool();
        $user   = $this->createUser($school, 'teacher');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
             ->assertOk();
    }

    public function test_forgot_password_rejects_unknown_email(): void
    {
        $this->seed(\Database\Seeders\RolesAndPlansSeeder::class);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@nowhere.com'])
             ->assertStatus(422);
    }
}
