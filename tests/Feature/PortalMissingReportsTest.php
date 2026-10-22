<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Administration / Missing Reports follows its rule and reach: the server lists only the people the
 * reach covers, with their filed days, and a reminder goes only to people in that list who have not
 * filed the day it names. Reminders are their own power, and their wording is the server's.
 */
class PortalMissingReportsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/administration/missing-reports';

    private Employee $admin;

    private Employee $colleague;

    private Employee $teammate;

    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $this->admin->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();

        [$this->colleague, $this->teammate, $this->outsider] = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->all();
        foreach ([$this->colleague, $this->teammate] as $index => $member) {
            $member->forceFill(['is_developer' => false, 'department' => 'Engineering', 'email' => 'member'.$index.'@ikaika.test'])->save();
        }
        $this->outsider->forceFill(['is_developer' => false, 'department' => 'Finance', 'email' => 'outsider@ikaika.test'])->save();
    }

    public function test_an_admin_reads_only_the_people_in_their_department_with_their_filed_days(): void
    {
        // Real reports start in 2025, so every fixture day here is in 2020, when nobody had filed.
        $this->insertLine($this->colleague, '2020-06-09');
        // Two lines on one day are still one filed day.
        $this->insertLine($this->colleague, '2020-06-09');
        $this->insertLine($this->colleague, '2020-06-10');
        $this->insertLine($this->outsider, '2020-06-11');

        $rows = collect($this->read('?from=2020-06-01&to=2020-06-30')->json('data'))->keyBy('employeeId');

        $days = $rows[(string) $this->colleague->getKey()]['days'];
        $this->assertSame(['2020-06-10', '2020-06-09'], $days);
        // The reach decides the roster: someone in their own department with nothing filed is still listed.
        $this->assertSame([], $rows[(string) $this->teammate->getKey()]['days']);
        $this->assertTrue($rows->has((string) $this->admin->getKey()));
        $this->assertFalse($rows->has((string) $this->outsider->getKey()));
        $this->assertSame(
            ['employeeId', 'name', 'email', 'isAdmin', 'days', 'earlierDay', 'reminders'],
            array_keys($rows[(string) $this->colleague->getKey()]),
        );
        $this->assertSame('member0@ikaika.test', $rows[(string) $this->colleague->getKey()]['email']);
        $this->assertTrue($rows[(string) $this->admin->getKey()]['isAdmin']);
    }

    public function test_a_member_quiet_for_longer_than_the_window_keeps_their_last_day(): void
    {
        $this->insertLine($this->colleague, '2020-03-02');

        $row = collect($this->read('?from=2020-03-03&to=2020-03-03')->json('data'))
            ->firstWhere('employeeId', (string) $this->colleague->getKey());

        $this->assertIsArray($row);
        $this->assertSame('2020-03-02', $row['earlierDay']);
    }

    public function test_without_the_rule_the_page_and_its_reminders_are_refused(): void
    {
        $headers = $this->headersFor($this->colleague);

        $this->getJson(self::BASE, $headers)->assertForbidden();
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => [(string) $this->teammate->getKey()]], $headers)
            ->assertForbidden();
    }

    public function test_a_reminder_goes_only_to_the_people_named_who_have_not_filed_that_day(): void
    {
        Mail::fake();
        $this->insertLine($this->teammate, '2020-06-10');

        $sent = $this->postJson(self::BASE.'/reminders', [
            'day' => '2020-06-10',
            'employeeIds' => [(string) $this->colleague->getKey(), (string) $this->teammate->getKey()],
        ], $this->headersFor($this->admin))
            ->assertCreated()
            ->assertJsonPath('data.recipientCount', 1)
            ->assertJsonPath('data.subject', 'Reminder: report not yet filed for Wednesday, June 10, 2020')
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, 1);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('member0@ikaika.test'));
        $this->assertSame('notice', EmailMessage::query()->findOrFail($sent['id'])->category);
        // A past day is only fileable as a late report, so the bell opens that form.
        $notice = PortalNotification::query()->where('employee_id', $this->colleague->getKey())->latest('id')->firstOrFail();
        $this->assertSame('/reports/late-report', $notice->link_path);

        // Marked on the page for that day (2026-10-21): the one emailed, never the one who had filed.
        $marks = collect($this->read('?from=2020-06-01&to=2020-06-30')->json('data'))->keyBy('employeeId');
        $this->assertSame('2020-06-10', $marks[(string) $this->colleague->getKey()]['reminders'][0]['day']);
        $this->assertSame([], $marks[(string) $this->teammate->getKey()]['reminders']);
    }

    // The reported ask (2026-10-21): a reminder sent from the Email page marks who was emailed for its day.
    public function test_a_reminder_sent_from_the_email_page_marks_who_was_emailed_for_its_day(): void
    {
        Mail::fake();
        $headers = $this->headersFor($this->admin);
        $draft = [
            'category' => 'notice', 'subject' => 'Reminder', 'body' => 'Please file it.',
            'audience' => 'members', 'memberIds' => [(string) $this->colleague->getKey()],
        ];

        $this->postJson('/api/development/portal/administration/emails', [...$draft, 'reminderFor' => '2020-06-10'], $headers)->assertCreated();

        $rows = collect($this->read('?from=2020-06-01&to=2020-06-30')->json('data'))->keyBy('employeeId');
        $marks = $rows[(string) $this->colleague->getKey()]['reminders'];
        $this->assertCount(1, $marks);
        $this->assertSame('2020-06-10', $marks[0]['day']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $marks[0]['sentAt']);
        $this->assertSame(PortalSubmittedReportPresenter::memberName($this->admin->first_name, $this->admin->last_name), $marks[0]['sentBy']);
        $this->assertSame([], $rows[(string) $this->teammate->getKey()]['reminders']);
        // A month the reminder is not about lists none.
        $july = collect($this->read('?from=2020-07-01&to=2020-07-31')->json('data'))->firstWhere('employeeId', (string) $this->colleague->getKey());
        $this->assertSame([], $july['reminders']);

        // An email that is no reminder marks nobody, and a day that does not exist is refused.
        $this->postJson('/api/development/portal/administration/emails', $draft, $headers)->assertCreated();
        $this->assertSame(1, DB::connection('portal')->table('report_reminders')->where('employee_id', $this->colleague->getKey())->count());
        $this->postJson('/api/development/portal/administration/emails', [...$draft, 'reminderFor' => '2020-02-31'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That reminder names a day that does not exist.');
    }

    public function test_a_reminder_is_refused_outside_the_reach_on_a_weekend_ahead_or_for_nobody(): void
    {
        Mail::fake();
        $headers = $this->headersFor($this->admin);
        $colleague = [(string) $this->colleague->getKey()];

        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => [(string) $this->outsider->getKey()]], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'Choose people from the list the page offers.');
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-13', 'employeeIds' => $colleague], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'Reports are not filed on weekends, so nobody is missing one.');
        $this->postJson(self::BASE.'/reminders', ['day' => '2099-06-10', 'employeeIds' => $colleague], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'That day has not come yet.');
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-02-30', 'employeeIds' => $colleague], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => []], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => ['x']], $headers)->assertStatus(422);

        $this->insertLine($this->colleague, '2020-06-10');
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => $colleague], $headers)
            ->assertStatus(422)->assertJsonPath('message', 'Everyone chosen has filed a report for that day.');
        Mail::assertNothingSent();
    }

    // Email reminders are a power of their own: switched off, the page still reads but nothing is sent.
    public function test_a_reminder_needs_its_own_power(): void
    {
        Mail::fake();
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $this->admin->getKey(), 'permission_key' => 'administration.missing.email']);
        $headers = $this->headersFor($this->admin);

        $this->getJson(self::BASE, $headers)->assertOk();
        $this->postJson(self::BASE.'/reminders', ['day' => '2020-06-10', 'employeeIds' => [(string) $this->colleague->getKey()]], $headers)
            ->assertForbidden();
        Mail::assertNothingSent();
    }

    private function read(string $query = ''): TestResponse
    {
        return $this->getJson(self::BASE.$query, $this->headersFor($this->admin))
            ->assertOk()
            ->assertJsonPath('resource', 'missing-reports');
    }

    private function insertLine(Employee $employee, string $day): void
    {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $day,
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'approval' => 'Approved',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
