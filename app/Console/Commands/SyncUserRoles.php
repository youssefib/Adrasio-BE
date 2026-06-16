<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SyncUserRoles extends Command
{
    protected $signature   = 'roles:sync';
    protected $description = 'Sync Spatie model_has_roles from the users.role column for all users.';

    public function handle(): int
    {
        $users = User::withTrashed()->get();
        $fixed = 0;

        foreach ($users as $user) {
            if (! $user->role) continue;

            $hasCorrectRole = $user->roles()->where('name', $user->role)->exists();
            if (! $hasCorrectRole) {
                $user->syncRoles([$user->role]);
                $fixed++;
                $this->line("  Fixed: {$user->email} → {$user->role}");
            }
        }

        $this->info("Done. Fixed {$fixed} / {$users->count()} users.");
        return self::SUCCESS;
    }
}
