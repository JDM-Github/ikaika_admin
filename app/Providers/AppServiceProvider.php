<?php

namespace App\Providers;

use App\Modules\Portal\Models\Employee;
use App\Support\ApiPath;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $directory = ApiPath::directory();
        if ($directory !== '' && blank(config('app.asset_url'))) {
            config(['app.asset_url' => '/'.$directory]);
        }

        // One browser tab paging a grid is a burst of reads, not a write path.
        RateLimiter::for('workspace', function (Request $request) {
            return Limit::perMinute(240)->by('workspace:'.$this->portalLimiterKey($request));
        });

        // A cell at a time by hand. Anything faster is a script and belongs elsewhere.
        RateLimiter::for('workspace-write', function (Request $request) {
            return Limit::perMinute(30)->by('workspace-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-home', function (Request $request) {
            return Limit::perMinute(60)->by('home:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-user-logs', function (Request $request) {
            return Limit::perMinute(60)->by('user-logs:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-projects', function (Request $request) {
            return Limit::perMinute(60)->by('projects:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-users', function (Request $request) {
            return Limit::perMinute(60)->by('manage-users:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-users-write', function (Request $request) {
            return Limit::perMinute(20)->by('manage-users-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-reports-submitted', function (Request $request) {
            return Limit::perMinute(60)->by('reports-submitted:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-reports-submitted-write', function (Request $request) {
            return Limit::perMinute(20)->by('reports-submitted-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-reports-projects', function (Request $request) {
            return Limit::perMinute(60)->by('reports-projects:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-requests', function (Request $request) {
            return Limit::perMinute(60)->by('requests:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-requests-write', function (Request $request) {
            return Limit::perMinute(20)->by('requests-write:'.$this->portalLimiterKey($request));
        });

        // Shared reference data that changes once a year, so the ceiling only has to stop a runaway.
        RateLimiter::for('portal-calendar-holidays', function (Request $request) {
            return Limit::perMinute(60)->by('calendar-holidays:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-recycle-bin', function (Request $request) {
            return Limit::perMinute(60)->by('administration-recycle-bin:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-recycle-bin-write', function (Request $request) {
            return Limit::perMinute(20)->by('administration-recycle-bin-write:'.$this->portalLimiterKey($request));
        });
    }

    private function portalLimiterKey(Request $request): string
    {
        $employee = $request->attributes->get('portalEmployee');
        if ($employee instanceof Employee) {
            return (string) $employee->getKey();
        }

        return (string) $request->ip();
    }
}
