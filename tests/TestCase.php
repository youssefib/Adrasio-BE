<?php

namespace Tests;

use App\Models\School;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear Spatie's in-memory permission cache before every test so stale
        // role IDs from a rolled-back transaction don't bleed into the next test.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    protected function createPlan(string $slug = 'basic'): SubscriptionPlan
    {
        // firstOrCreate so calling createSchool() after seed() doesn't
        // hit a unique-slug violation within the same test transaction.
        return SubscriptionPlan::firstOrCreate(
            ['slug' => $slug],
            [
                'name'             => ucfirst($slug),
                'max_students'     => 100,
                'max_teachers'     => 10,
                'max_classes'      => 10,
                'storage_limit_mb' => 512,
                'price_monthly'    => 9.99,
                'price_yearly'     => 99.00,
            ]
        );
    }

    protected function createSchool(array $overrides = []): School
    {
        $plan = $this->createPlan();

        return School::create(array_merge([
            'subscription_plan_id' => $plan->id,
            'subscription_tier'    => 'tier1',
            'name'                 => 'Test School',
            'slug'                 => 'test-school-' . uniqid(),
            'email'                => 'school' . uniqid() . '@test.com',
            'status'               => 'active',
        ], $overrides));
    }

    protected function createUser(School $school, string $role, array $overrides = []): User
    {
        $user = User::create(array_merge([
            'school_id' => $school->id,
            'role'      => $role,
            'name'      => ucfirst($role) . ' User',
            'email'     => $role . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'is_active' => true,
        ], $overrides));

        $user->assignRole($role);

        return $user;
    }

    protected function createSystemAdmin(): User
    {
        $user = User::create([
            'school_id' => null,
            'role'      => 'system_admin',
            'name'      => 'System Admin',
            'email'     => 'sysadmin' . uniqid() . '@test.com',
            'password'  => bcrypt('password'),
            'is_active' => true,
        ]);

        $user->assignRole('system_admin');

        return $user;
    }

    protected function actingAsUser(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
