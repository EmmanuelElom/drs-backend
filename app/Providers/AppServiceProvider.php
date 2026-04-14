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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(AppSetting::class, AppSettingPolicy::class);
    }
}
