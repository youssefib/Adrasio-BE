<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = \App\Models\User::firstOrCreate(
            ['email' => env('SYSTEM_ADMIN_EMAIL', 'admin@suimedrassa.com')],
            [
                'name'      => 'System Administrator',
                'password'  => \Illuminate\Support\Facades\Hash::make(
                    env('SYSTEM_ADMIN_PASSWORD', 'Admin@12345')
                ),
                'school_id' => null,
                'role'      => 'system_admin',
                'is_active' => true,
            ]
        );

        // Ensure existing record also has the correct role column
        if ($admin->role !== 'system_admin') {
            $admin->update(['role' => 'system_admin']);
        }

        $admin->assignRole('system_admin');
    }
}
