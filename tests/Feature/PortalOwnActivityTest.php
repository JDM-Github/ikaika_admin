<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreRecycleKey;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\FilesPortalReports;
use Tests\Concerns\ManagesPortalAccess;
use Tests\TestCase;

/**
 * A developer's own work (their reports) in Developer mode: theirs by name while Default would allow
 * it; past Default (a boss limited it) a write waits for their confirmation and is logged as
 * "A developer". The confirmation rides as a header on that one request only.
 */
class PortalOwnActivityTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;
    use ManagesPortalAccess;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const CONFIRMED = [PortalRules::CONFIRM_HEADER => '1'];

    private Employee $developer;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->clearAccessTables();

        $withProject = 'id IN (SELECT employee_id FROM employees_projects)';
        $this->developer = $this->activeWhere("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive' AND {$withProject}", 1)[0];
        $this->admin = $this->activeWhere("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 1)[0];
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
    }

    public function test_own_work_within_default_is_named_even_in_developer_mode(): void
    {
        $date = $this->freeReportDate($this->developer);
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $this->dailyReportBody($this->developer, $date))->assertCreated();

        $log = $this->reportLog($date);
        $this->assertFalse((bool) $log->as_developer);
        // A developer's activity shows only to someone who may see developers.
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->admin->id, 'permission_key' => PortalRules::DEVELOPERS_ACTIVITY, 'reach' => null]);
        $row = collect($this->asMode('default', $this->admin)->getJson('/api/development/portal/administration/all-logs?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', (string) $log->id);
        $this->assertSame($this->displayName($this->developer), $row['employeeName'] ?? null);
    }

    // The boss case: an admin took Daily Report away, so Developer mode asks first and the log hides who.
    public function test_filing_past_a_limit_waits_for_confirmation_and_is_logged_as_a_developer(): void
    {
        $this->change('default', $this->admin, $this->developer, ['key' => 'reports.daily', 'action' => 'off'])->assertOk();
        $date = $this->freeReportDate($this->developer);
        $body = $this->dailyReportBody($this->developer, $date);

        $this->asMode('default', $this->developer)->postJson('/api/development/portal/reports/submitted', $body)->assertForbidden();
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $body)
            ->assertStatus(428)
            ->assertJsonPath('code', PortalRules::CONFIRM_CODE)
            ->assertJsonPath('message', 'You cannot use "Reports / Daily Report" in Default: someone limited it, or your role does not include it. Developer mode can still do it, but the log will read "A developer" instead of your name.');
        $this->assertNull($this->reportLog($date));

        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $body, self::CONFIRMED)->assertCreated();
        $this->assertTrue((bool) $this->reportLog($date)?->as_developer);
    }

    // Reading never asks: only a write leaves a record behind.
    public function test_edit_and_delete_past_a_limit_wait_for_confirmation(): void
    {
        $date = $this->freeReportDate($this->developer);
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $this->dailyReportBody($this->developer, $date))->assertCreated();
        $this->change('default', $this->admin, $this->developer, ['key' => 'reports.submitted', 'action' => 'off'])->assertOk();
        $path = "/api/development/portal/reports/submitted/{$date}-daily";
        $edit = ['entries' => $this->reportEntries($this->developer, 6.5), 'remarks' => 'Adjusted'];

        $this->asMode('dev', $this->developer)->getJson("/api/development/portal/reports/submitted?from={$date}&to={$date}")->assertOk();
        $this->asMode('dev', $this->developer)->patchJson($path, $edit)->assertStatus(428)->assertJsonPath('code', PortalRules::CONFIRM_CODE);
        $this->asMode('dev', $this->developer)->patchJson($path, $edit, self::CONFIRMED)->assertOk();
        $this->assertTrue((bool) $this->reportLog($date, 'PATCH')?->as_developer);

        $this->asMode('dev', $this->developer)->deleteJson($path)->assertStatus(428);
        $this->asMode('dev', $this->developer)->deleteJson($path, [], self::CONFIRMED)->assertNoContent();
        $this->assertTrue((bool) $this->reportLog($date, 'DELETE')?->as_developer);
    }

    // Your own switch-off holds in both modes, so there is nothing to confirm: it is simply refused.
    public function test_a_power_switched_off_yourself_stays_off_in_developer_mode(): void
    {
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $this->developer->id, 'permission_key' => 'reports.daily']);
        $date = $this->freeReportDate($this->developer);

        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $this->dailyReportBody($this->developer, $date), self::CONFIRMED)
            ->assertForbidden();
    }

    // Every write a person makes on their own work carries portal.own, so none can skip the warning.
    public function test_every_own_work_write_waits_past_default(): void
    {
        $own = [
            'PATCH reports/submitted/{id}', 'DELETE reports/submitted/{id}',
            'POST requests/leave', 'PATCH requests/leave/{id}', 'POST requests/leave/{id}/cancel',
            'POST requests/overtime', 'PATCH requests/overtime/{id}', 'POST requests/overtime/{id}/cancel',
            'POST requests/offset', 'PATCH requests/offset/{id}', 'POST requests/offset/{id}/cancel',
            'POST requests/reimbursement', 'PATCH requests/reimbursement/{id}', 'POST requests/reimbursement/{id}/cancel',
            'POST calendar/events',
        ];
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $uri = preg_replace('#^.*?/portal/#', '', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $name = $method.' '.$uri;
                if (in_array($name, $own, true)) {
                    $this->assertNotEmpty(array_filter($route->gatherMiddleware(), static fn ($m): bool => is_string($m) && str_starts_with($m, 'portal.own:')), $name);
                    $found[$name] = true;
                }
            }
        }
        $this->assertSame([], array_values(array_diff($own, array_keys($found))));
    }

    public function test_a_leave_request_past_a_limit_waits_for_confirmation(): void
    {
        $this->change('default', $this->admin, $this->developer, ['key' => 'requests.leave', 'action' => 'off'])->assertOk();
        $day = $this->freeRequestDay($this->developer);
        $body = ['leaveType' => '02 Sick Leave', 'startDate' => $day, 'endDate' => $day, 'reason' => 'Checkup'];

        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/requests/leave', $body)
            ->assertStatus(428)->assertJsonPath('code', PortalRules::CONFIRM_CODE);
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/requests/leave', $body, self::CONFIRMED)->assertCreated();

        $log = DB::connection('portal')->table('logs')->where('employee_id', $this->developer->id)->where('resource', 'like', 'requests.leave%')->orderByDesc('id')->first();
        $this->assertTrue((bool) $log?->as_developer);
        // The manager's alert says what the log says.
        $this->assertStringStartsWith('A developer filed a leave request', (string) $this->latestAlert($this->admin, 'filed a leave request'));
    }

    public function test_a_leave_request_within_default_alerts_by_name(): void
    {
        $day = $this->freeRequestDay($this->developer);
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave', 'startDate' => $day, 'endDate' => $day, 'reason' => 'Checkup',
        ])->assertCreated();

        $this->assertStringStartsWith(PortalActivityCopy::displayName($this->developer).' filed a leave request', (string) $this->latestAlert($this->admin, 'filed a leave request'));
    }

    // Other people read a developer's act in Developer mode as "a developer", unless it is their own work within Default.
    public function test_the_name_others_read_follows_the_act(): void
    {
        $request = Request::create('/api/development/portal/reports/submitted', 'GET');
        $request->attributes->set('portalSessionId', 'own-activity-session');
        DB::connection('portal')->table('developer_modes')->updateOrInsert(['employee_id' => $this->developer->id], ['active_session' => 'own-activity-session']);
        $name = PortalActivityCopy::displayName($this->developer);

        $this->assertSame($name, PortalActivityCopy::actorName($this->developer, null));
        $this->assertSame('a developer', PortalActivityCopy::actorName($this->developer, $request));
        $this->assertSame('A developer', PortalActivityCopy::actorName($this->developer, $request, true));

        PortalRules::requireOwn($this->developer, $request, 'reports.daily');
        $this->assertSame($name, PortalActivityCopy::actorName($this->developer, $request));
        $this->assertSame(PortalActivityCopy::displayName($this->admin), PortalActivityCopy::actorName($this->admin, $request, true));
    }

    // The session lists Default's powers only in Developer mode, so the portal can warn before an export past them.
    public function test_the_session_names_what_default_allows_in_developer_mode(): void
    {
        $this->change('default', $this->admin, $this->developer, ['key' => 'reports.submitted.export', 'action' => 'off'])->assertOk();

        $this->assertNull($this->asMode('default', $this->developer)->getJson('/api/development/portal/auth/me')->assertOk()->json('employee.default_rules'));
        $inDefault = $this->asMode('dev', $this->developer)->getJson('/api/development/portal/auth/me')->assertOk()->json('employee.default_rules');
        $this->assertContains('reports.submitted', $inDefault);
        $this->assertNotContains('reports.submitted.export', $inDefault);
    }

    // Add events is admins and above: a member-developer lacks it by role, which is past Default too.
    public function test_adding_an_event_the_role_lacks_waits_for_confirmation(): void
    {
        $day = $this->freeRequestDay($this->developer);
        $body = ['title' => 'Dry run', 'startsOn' => $day, 'category' => 'company_event', 'audience' => 'everyone'];

        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/calendar/events', $body)
            ->assertStatus(428)
            ->assertJsonPath('message', 'You cannot use "Event Calendar / Add events" in Default: someone limited it, or your role does not include it. Developer mode can still do it, but the log will read "A developer" instead of your name.');
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/calendar/events', $body, self::CONFIRMED)->assertCreated();
    }

    public function test_restoring_your_own_row_past_a_limit_waits_for_confirmation(): void
    {
        $date = $this->freeReportDate($this->developer);
        $this->asMode('dev', $this->developer)->postJson('/api/development/portal/reports/submitted', $this->dailyReportBody($this->developer, $date))->assertCreated();
        $this->asMode('dev', $this->developer)->deleteJson("/api/development/portal/reports/submitted/{$date}-daily")->assertNoContent();
        $recycled = Recycle::query()->where('recycle_key', CoreRecycleKey::submittedReport((int) $this->developer->id, $date, 'daily'))->orderByDesc('id')->first();
        $this->assertNotNull($recycled);
        $this->change('default', $this->admin, $this->developer, ['key' => 'user.recycle', 'action' => 'off'])->assertOk();
        $path = "/api/development/portal/administration/recycle-bin/{$recycled->id}/restore";

        $this->asMode('dev', $this->developer)->postJson($path)->assertStatus(428);
        $this->asMode('dev', $this->developer)->postJson($path, [], self::CONFIRMED)->assertNoContent();
        $this->assertTrue((bool) $this->reportLog($date)?->as_developer);
    }

    private function latestAlert(Employee $recipient, string $containing): ?string
    {
        $message = DB::connection('portal')->table('notifications')
            ->where('employee_id', $recipient->id)
            ->where('message', 'like', '%'.$containing.'%')
            ->orderByDesc('id')
            ->value('message');

        return is_string($message) ? $message : null;
    }

    private function reportLog(string $date, string $action = 'INSERT'): ?object
    {
        return DB::connection('portal')->table('logs')
            ->where('employee_id', $this->developer->id)
            ->where('resource', 'reports.submitted')
            ->where('action', $action)
            ->where('record_id', $date.'-daily')
            ->orderByDesc('id')
            ->first();
    }

    private function displayName(Employee $employee): string
    {
        return trim(trim((string) $employee->first_name).' '.trim((string) $employee->last_name));
    }
}
