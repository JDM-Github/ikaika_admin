<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAdvancedSettings;
use App\Support\Portal\PortalNotificationType;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The mapped inbox notices also leave as emails: the notice as a letter with the Company footer,
 * from the portal's own address, behind the notification_emails setting. The inbox row is written
 * either way; a mail failure is only a warning.
 */
class PortalNotificationEmailsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const LEAVE_ENDPOINT = '/api/development/portal/requests/leave';

    private const MANAGE_REQUESTS_ENDPOINT = '/api/development/portal/manage/requests';

    private const PROJECTS_ENDPOINT = '/api/development/portal/projects';

    private const MANAGE_PROJECTS_ENDPOINT = '/api/development/portal/manage/projects';

    private const CALENDAR_ENDPOINT = '/api/development/portal/calendar/events';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['products.catalog.portal.notification_emails' => true]);
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::NOTIFICATION_EMAILS)
            ->delete();
    }

    public function test_filing_a_leave_request_emails_the_department_admins(): void
    {
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $date = $this->freeWeekday();

        $this->withToken($this->token($member))->postJson(self::LEAVE_ENDPOINT, [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family event',
        ])->assertCreated();

        Mail::assertSent(PortalEmailMail::class, function (PortalEmailMail $mail) use ($admin, $member): bool {
            $html = $mail->render();

            return $mail->hasTo((string) $admin->email)
                && $mail->subjectLine === 'New leave request'
                // The Company footer rides under the message, as the composer sends it.
                && str_contains($html, 'IKAIKA BIM')
                && str_contains($html, $this->memberName($member))
                && str_contains($html, '/manage/manage-requests');
        });

        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $admin->getKey())
            ->where('type', PortalNotificationType::REQUEST_FILED_LEAVE)
            ->exists());
    }

    public function test_a_request_decision_emails_the_owner(): void
    {
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $id = $this->insertLeave($member, $this->freeWeekday());

        $this->withToken($this->token($admin))->patchJson(self::MANAGE_REQUESTS_ENDPOINT, [
            'changes' => [[
                'id' => (string) $id,
                'kind' => 'leave',
                'status' => 'approved',
            ]],
        ])->assertOk();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $member->email)
            && $mail->subjectLine === 'Request approved'
            && str_contains($mail->body, $this->memberName($admin)));
    }

    public function test_a_project_approval_emails_the_requester(): void
    {
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $projectId = (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_name' => 'Approval Notice '.Str::random(8),
            'department' => 'MIS',
            'status' => 'Started',
            'project_creation_status' => 'Pending',
            'requested_by' => $member->getKey(),
            'requested_at' => now(),
        ]);

        $this->withToken($this->token($admin))
            ->patchJson(self::MANAGE_PROJECTS_ENDPOINT.'/'.$projectId, ['decision' => 'approve'])
            ->assertOk();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $member->email)
            && $mail->subjectLine === 'Project approved');
    }

    public function test_creating_a_project_emails_the_managers_and_the_members(): void
    {
        $admin = $this->admin('MIS');
        $otherAdmin = $this->admin('MIS', 1);
        $member = $this->member('MIS');
        $payload = $this->projectPayload($admin, $member, 'Notice Project '.Str::random(8));

        $this->withToken($this->token($admin))->postJson(self::PROJECTS_ENDPOINT, $payload)->assertCreated();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $otherAdmin->email)
            && $mail->subjectLine === 'New project to approve');
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $member->email)
            && $mail->subjectLine === 'You were added to a project'
            && str_contains($mail->body, $payload['name']));
    }

    public function test_editing_a_project_emails_the_managers(): void
    {
        $admin = $this->admin('MIS');
        $otherAdmin = $this->admin('MIS', 1);
        $member = $this->member('MIS');
        $payload = $this->projectPayload($admin, $member, 'Edit Notice '.Str::random(8));

        $id = (int) $this->withToken($this->token($admin))
            ->postJson(self::PROJECTS_ENDPOINT, $payload)
            ->assertCreated()
            ->json('data.id');

        $this->withToken($this->token($admin))
            ->patchJson(self::PROJECTS_ENDPOINT.'/'.$id, [...$payload, 'status' => 'completed'])
            ->assertOk();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $otherAdmin->email)
            && $mail->subjectLine === 'Project updated'
            && str_contains($mail->body, $payload['name']));
    }

    public function test_creating_an_event_emails_invitees_and_the_managers(): void
    {
        $actor = $this->member('MIS');
        $invitee = $this->member(null, 1);
        $admin = $this->admin('MIS');
        $this->giveAddEvents($actor);
        $date = $this->freeWeekday();

        $this->withToken($this->token($actor))->postJson(self::CALENDAR_ENDPOINT, [
            'title' => 'Design review',
            'startsOn' => $date,
            'category' => 'company_event',
            'audience' => 'members',
            'memberIds' => [(int) $invitee->getKey()],
        ])->assertCreated();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $invitee->email)
            && $mail->subjectLine === 'New event'
            && str_contains($mail->body, 'Design review'));
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $admin->email)
            && $mail->subjectLine === 'New event'
            && str_contains($mail->body, 'Design review'));
    }

    public function test_the_setting_turns_notification_emails_off(): void
    {
        config(['products.catalog.portal.notification_emails' => false]);
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $date = $this->freeWeekday();

        $this->withToken($this->token($member))->postJson(self::LEAVE_ENDPOINT, [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family event',
        ])->assertCreated();

        Mail::assertNothingSent();
        // The inbox row is written either way; only the email is off.
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $admin->getKey())
            ->where('type', PortalNotificationType::REQUEST_FILED_LEAVE)
            ->exists());
    }

    // The stored choice is the setting now; the .env value only fills in when nothing is stored.
    public function test_a_stored_off_choice_beats_the_config_on(): void
    {
        $this->storeNotificationEmails(false);
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $date = $this->freeWeekday();

        $this->withToken($this->token($member))->postJson(self::LEAVE_ENDPOINT, [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family event',
        ])->assertCreated();

        Mail::assertNothingSent();
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $admin->getKey())
            ->where('type', PortalNotificationType::REQUEST_FILED_LEAVE)
            ->exists());
    }

    public function test_a_stored_on_choice_beats_the_config_off(): void
    {
        config(['products.catalog.portal.notification_emails' => false]);
        $this->storeNotificationEmails(true);
        $member = $this->member('MIS');
        $admin = $this->admin('MIS');
        $date = $this->freeWeekday();

        $this->withToken($this->token($member))->postJson(self::LEAVE_ENDPOINT, [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family event',
        ])->assertCreated();

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $admin->email));
    }

    public function test_a_mail_failure_never_fails_the_write(): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function () {
            $pending = Mockery::mock();
            $pending->shouldReceive('send')->andThrow(new RuntimeException('550 relay denied'));

            return $pending;
        });

        $member = $this->member('MIS');
        $this->admin('MIS');
        $date = $this->freeWeekday();

        $this->withToken($this->token($member))->postJson(self::LEAVE_ENDPOINT, [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family event',
        ])->assertCreated();

        $this->assertSame(1, DB::connection('portal')->table('requests')->where('request_date', $date)->count());
    }

    private function storeNotificationEmails(bool $on): void
    {
        DB::connection('core')->table('settings')->updateOrInsert(
            ['product' => 'portal', 'setting_key' => PortalAdvancedSettings::NOTIFICATION_EMAILS],
            ['setting_value' => $on ? '1' : '0'],
        );
    }

    private function admin(string $department, int $skip = 0): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->skip($skip)
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false, 'department' => $department])->save();

        return $employee;
    }

    private function member(?string $department = null, int $skip = 0): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->skip($skip)
            ->firstOrFail();
        $fill = ['is_developer' => false];
        if ($department !== null) {
            $fill['department'] = $department;
        }
        $employee->forceFill($fill)->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(Employee $lead, Employee $member, string $name): array
    {
        return [
            'name' => $name,
            'leadId' => (int) $lead->getKey(),
            'clientId' => (int) DB::connection('portal')->table('clients')->orderBy('id')->value('id'),
            'department' => 'MIS',
            'status' => 'ongoing',
            'memberIds' => [(int) $member->getKey()],
        ];
    }

    private function freeWeekday(): string
    {
        for ($offset = 3; $offset < 200; $offset++) {
            $day = Carbon::today()->addDays($offset);
            if ($day->isWeekend()) {
                continue;
            }
            if (! DB::connection('portal')->table('requests')->where('request_date', $day->toDateString())->exists()) {
                return $day->toDateString();
            }
        }

        $this->fail('No free weekday was available.');
    }

    private function insertLeave(Employee $member, string $date): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($member),
            'reason' => 'Family event',
            'status' => 'Pending',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
            'date_created' => $date.' 09:00:00',
        ]);
    }

    private function memberName(Employee $actor): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
    }

    // A developer's gift, the way a manager would give it; members do not hold Add events by default.
    private function giveAddEvents(Employee $employee): void
    {
        $giver = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('id', '!=', $employee->getKey())
            ->orderBy('id')
            ->firstOrFail();
        $giver->forceFill(['is_developer' => true])->save();
        DB::connection('portal')->table('access_grants')->updateOrInsert(
            ['employee_id' => $employee->getKey(), 'permission_key' => 'calendar.create', 'granted_by' => $giver->getKey()],
            ['reach' => null, 'include_executives' => false],
        );
    }
}
