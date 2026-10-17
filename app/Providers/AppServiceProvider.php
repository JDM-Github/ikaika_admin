<?php

namespace App\Providers;

use App\Modules\Portal\Models\Employee;
use App\Support\ApiPath;
use App\Support\Core\CoreDeveloperActs;
use App\Support\Portal\PortalCoreDeveloperActs;
use App\Support\Portal\PortalMailRelayTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CoreDeveloperActs::class, PortalCoreDeveloperActs::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('wprelay', fn (array $config): PortalMailRelayTransport => new PortalMailRelayTransport(
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['key'] ?? ''),
        ));

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

        RateLimiter::for('portal-auth', function (Request $request) {
            return Limit::perMinute(10)->by('auth:'.(string) $request->ip());
        });

        // A guest on a shared page: the share's own cap is on its token; this holds the door.
        RateLimiter::for('portal-share', function (Request $request) {
            return Limit::perMinute(30)->by('share:'.(string) $request->ip());
        });

        // Signing into a share: a password and a code are checked on these routes.
        RateLimiter::for('portal-share-auth', function (Request $request) {
            $code = $request->route('code');

            return Limit::perMinute(5)->by('share-auth:'.(is_scalar($code) ? (string) $code : '').':'.(string) $request->ip());
        });

        // Signing into a registration link: the same door, and the session's own cap sits on the
        // middleware (a candidate uploads a document per request, so the sign-in cap would be wrong).
        RateLimiter::for('portal-register-auth', function (Request $request) {
            $code = $request->route('code');

            return Limit::perMinute(5)->by('register-auth:'.(is_scalar($code) ? (string) $code : '').':'.(string) $request->ip());
        });

        RateLimiter::for('portal-home', function (Request $request) {
            return Limit::perMinute(60)->by('home:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-user-logs', function (Request $request) {
            return Limit::perMinute(60)->by('user-logs:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-user-notifications', function (Request $request) {
            return Limit::perMinute(60)->by('user-notifications:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-user-notifications-write', function (Request $request) {
            return Limit::perMinute(30)->by('user-notifications-write:'.$this->portalLimiterKey($request));
        });

        // Their own employee information, and the changes they ask for to it (2026-10-14).
        RateLimiter::for('portal-user-information', function (Request $request) {
            return Limit::perMinute(60)->by('user-information:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-user-information-write', function (Request $request) {
            return Limit::perMinute(10)->by('user-information-write:'.$this->portalLimiterKey($request));
        });

        // A password is checked here, so guesses are held to a handful a minute.
        RateLimiter::for('portal-developer', function (Request $request) {
            return Limit::perMinute(5)->by('developer:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-access', function (Request $request) {
            return Limit::perMinute(60)->by('access:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-access-write', function (Request $request) {
            return Limit::perMinute(60)->by('access-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-access', function (Request $request) {
            return Limit::perMinute(60)->by('manage-access:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-access-write', function (Request $request) {
            return Limit::perMinute(60)->by('manage-access-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('bitbucket-webhook', function (Request $request) {
            return Limit::perMinute(60)->by('bitbucket-webhook:'.$request->ip());
        });

        RateLimiter::for('portal-projects', function (Request $request) {
            return Limit::perMinute(60)->by('projects:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-projects-write', function (Request $request) {
            return Limit::perMinute(20)->by('projects-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-users', function (Request $request) {
            return Limit::perMinute(60)->by('manage-users:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-users-write', function (Request $request) {
            return Limit::perMinute(20)->by('manage-users-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-requests', function (Request $request) {
            return Limit::perMinute(60)->by('manage-requests:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-requests-write', function (Request $request) {
            return Limit::perMinute(20)->by('manage-requests-write:'.$this->portalLimiterKey($request));
        });

        // Ten reports an hour each. The counter is the only place the sender is known, and it never meets the report.
        RateLimiter::for('portal-bug-report', function (Request $request) {
            return Limit::perHour(10)->by('bug-report:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-bug-report-write', function (Request $request) {
            return Limit::perMinute(30)->by('bug-report-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-budget-write', function (Request $request) {
            return Limit::perMinute(20)->by('manage-budget-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-manage-reports', function (Request $request) {
            return Limit::perMinute(60)->by('manage-reports:'.$this->portalLimiterKey($request));
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

        RateLimiter::for('portal-calendar-events', function (Request $request) {
            return Limit::perMinute(60)->by('calendar-events:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-calendar-events-write', function (Request $request) {
            return Limit::perMinute(20)->by('calendar-events-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-calendar-event-options', function (Request $request) {
            return Limit::perMinute(60)->by('calendar-event-options:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-all-logs', function (Request $request) {
            return Limit::perMinute(60)->by('administration-all-logs:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-all-actions', function (Request $request) {
            return Limit::perMinute(60)->by('administration-all-actions:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-recycle-bin', function (Request $request) {
            return Limit::perMinute(60)->by('administration-recycle-bin:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-recycle-bin-write', function (Request $request) {
            return Limit::perMinute(20)->by('administration-recycle-bin-write:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-email', function (Request $request) {
            return Limit::perMinute(60)->by('administration-email:'.$this->portalLimiterKey($request));
        });

        // The composer rebuilds its preview every time writing pauses, so the ordinary email limit
        // would blank it after a paragraph. It writes nothing, so it gets a ceiling of its own.
        RateLimiter::for('portal-email-preview', function (Request $request) {
            return Limit::perMinute(240)->by('email-preview:'.$this->portalLimiterKey($request));
        });

        // The Report remarks "Polish" button reaches a model on every press, so it gets its own
        // modest ceiling: nobody tidies a note ten times a minute, and the model is not free.
        RateLimiter::for('portal-report-remark', function (Request $request) {
            return Limit::perMinute(10)->by('report-remark:'.$this->portalLimiterKey($request));
        });

        RateLimiter::for('portal-administration-email-write', function (Request $request) {
            return Limit::perMinute(20)->by('administration-email-write:'.$this->portalLimiterKey($request));
        });

        // Sending is heavier than any other write here: it reaches the mail transport once per recipient.
        RateLimiter::for('portal-administration-email-send', function (Request $request) {
            return Limit::perMinute(10)->by('administration-email-send:'.$this->portalLimiterKey($request));
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
