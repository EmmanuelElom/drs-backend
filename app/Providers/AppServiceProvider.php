<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use App\Policies\AppSettingPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\UserPolicy;
use App\Services\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep legacy MySQL index lengths compatible with utf8mb4 columns.
        Schema::defaultStringLength(191);

        RateLimiter::for('auth-login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    Str::lower((string) $request->input('username')) . '|' . $request->ip()
                ),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    Str::lower((string) $request->input('email')) . '|' . $request->ip()
                ),
            ];
        });

        RateLimiter::for('waitlist-submissions', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    Str::lower((string) $request->input('email')) . '|' . $request->ip()
                ),
            ];
        });

        Auth::viaRequest('jwt', function (Request $request) {
            return app(JwtService::class)->resolveUserFromRequest($request);
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(AppSetting::class, AppSettingPolicy::class);
    }
}
