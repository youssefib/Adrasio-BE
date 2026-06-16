<?php

namespace App\Providers;

use App\Models\File;
use App\Policies\FilePolicy;
use App\Services\CurrentSchool;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CurrentSchool is a per-request singleton (reset each request)
        $this->app->singleton(CurrentSchool::class);

        // Alias for convenience: app('current_school') returns the School model instance
        $this->app->bind('current_school', fn () => app(CurrentSchool::class)->get());
    }

    public function boot(): void
    {
        Gate::policy(File::class, FilePolicy::class);

        // Point password-reset emails at the frontend SPA instead of a Laravel
        // named route (this is an API-only app — no web reset route exists).
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
            return $base . '/reset-password?token=' . $token . '&email=' . urlencode($user->getEmailForPasswordReset());
        });
    }
}
