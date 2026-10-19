<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalReportReminders;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The scheduled missing-report reminder: the last five work days up to yesterday, minus filed days,
 * leave, and days outside someone's employment, one outbox email each; a developer's sample goes
 * straight to one address and a dry run sends nothing.
 */
class PortalReportRemindersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    // Real reports start in 2025, so a 2020 Monday has a window nobody filed in: Mar 9 to 13.
    private const TODAY = '2020-03-16 17:00:00';

    private Employee $member;

    private Employee $starter;

    private Employee $leaver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        [$this->member, $this->starter, $this->leaver] = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->all();
        $this->member->forceFill(['email' => 'member@ikaika.test', 'start_date' => null, 'end_date' => null])->save();
        $this->starter->forceFill(['email' => 'starter@ikaika.test', 'start_date' => '2020-03-12', 'end_date' => null])->save();
        $this->leaver->forceFill(['email' => 'leaver@ikaika.test', 'start_date' => null, 'end_date' => '2020-03-09'])->save();
    }

    public function test_the_window_skips_filed_days_leave_offsets_and_days_outside_employment(): void
    {
        $this->insertLine($this->member, '2020-03-10');
        DB::connection('portal')->table('requests')->insert([
            'name' => trim($this->member->first_name.' '.$this->member->last_name),
            'type' => 'Leave',
            'status' => 'Approved',
            'request_date' => '2020-03-11',
        ]);
        DB::connection('portal')->table('requests')->insert([
            'name' => trim($this->starter->first_name.' '.$this->starter->last_name),
            'type' => 'Offset',
            'status' => 'Approved',
            'original_work_day' => '2020-03-07',
            'offset_work_day' => '2020-03-13',
        ]);

        $plan = collect($this->reminders()->missing(Carbon::parse(self::TODAY, PortalReportReminders::ZONE)))->keyBy('email');

        $this->assertSame(['2020-03-09', '2020-03-12', '2020-03-13'], $plan['member@ikaika.test']['days']);
        // The offset's day off is covered, so only the 12th is missing.
        $this->assertSame(['2020-03-12'], $plan['starter@ikaika.test']['days']);
        $this->assertSame(['2020-03-09'], $plan['leaver@ikaika.test']['days']);
        $executive = $this->rosterExecutive();
        $this->assertFalse($plan->contains(fn (array $row): bool => $row['employee']->is($executive)));
    }

    public function test_a_reminder_lands_in_the_outbox_for_that_person_only(): void
    {
        Mail::fake();

        $this->reminders()->remind($this->member, ['2020-03-09', '2020-03-12']);

        Mail::assertSent(PortalEmailMail::class, 1);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('member@ikaika.test'));
        $row = EmailMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('Reminder: 2 reports not yet filed', $row->subject);
        $this->assertStringContainsString('- Monday, March 9, 2020', (string) $row->body);
    }

    public function test_a_sample_goes_straight_to_the_address_and_skips_the_outbox(): void
    {
        Mail::fake();
        $before = (int) EmailMessage::query()->max('id');

        $this->reminders()->sample('dev@ikaika.test', $this->reminders()->letter(['2020-03-09']));

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('dev@ikaika.test'));
        $this->assertSame($before, (int) EmailMessage::query()->max('id'));
    }

    public function test_each_department_head_gets_only_their_own_team_and_never_themselves(): void
    {
        $head = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotIn('id', [$this->member->getKey(), $this->starter->getKey(), $this->leaver->getKey()])
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->orderBy('id')
            ->firstOrFail();
        $head->forceFill(['email' => 'head@ikaika.test', 'department' => 'QA Test', 'role_level' => 'Management'])->save();
        $executive = $this->rosterExecutive();
        $executive->forceFill(['email' => 'boss@ikaika.test', 'department' => 'QA Test'])->save();
        $row = fn (Employee $person, string $department): array => ['employee' => $person, 'name' => (string) $person->first_name, 'email' => 'x@ikaika.test', 'department' => $department, 'days' => ['2020-03-09']];

        $summaries = $this->reminders()->summaries([
            $row($this->member, 'QA Test'),
            $row($this->starter, 'qa test'),
            $row($this->leaver, 'Other'),
            $row($head, 'QA Test'),
        ]);

        $ours = collect($summaries)->filter(fn (array $summary): bool => $summary['head']->is($head))->values();
        $this->assertCount(1, $ours);
        $this->assertSame([(string) $this->member->first_name, (string) $this->starter->first_name], array_column($ours[0]['members'], 'name'));
        $this->assertFalse(collect($summaries)->contains(fn (array $summary): bool => $summary['head']->is($executive)));
    }

    public function test_a_summary_lands_in_the_head_outbox_linking_missing_reports(): void
    {
        Mail::fake();

        $this->reminders()->summarize([
            'head' => $this->member,
            'department' => 'QA Test',
            'members' => [['name' => 'Ana Cruz', 'days' => ['2020-03-09', '2020-03-10']]],
        ]);

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('member@ikaika.test'));
        $row = EmailMessage::query()->latest('id')->firstOrFail();
        $this->assertSame('Reminder: QA Test team missing report dates', $row->subject);
        $this->assertStringContainsString('- Ana Cruz (2 missing): Mon, Mar 9, Tue, Mar 10', (string) $row->body);
        $notice = PortalNotification::query()->where('employee_id', $this->member->getKey())->latest('id')->firstOrFail();
        $this->assertSame('/administration/missing-reports', $notice->link_path);
    }

    public function test_the_command_dry_run_sends_nothing_and_a_bad_address_is_refused(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse(self::TODAY, PortalReportReminders::ZONE));

        $this->artisan('portal:remind-missing-reports', ['--dry-run' => true])
            ->expectsOutputToContain('member@ikaika.test')
            ->assertExitCode(0);
        $this->artisan('portal:remind-missing-reports', ['--to' => 'not-an-address'])->assertExitCode(1);

        Mail::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_a_refused_sample_is_reported_not_claimed_as_sent(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, PortalReportReminders::ZONE));
        Mail::shouldReceive('to')->andThrow(new RuntimeException('bridge refused'));

        $this->artisan('portal:remind-missing-reports', ['--to' => 'dev@ikaika.test'])
            ->expectsOutputToContain('Not sent: bridge refused')
            ->assertExitCode(1);
        Carbon::setTestNow();
    }

    public function test_it_is_scheduled_on_weekdays_at_six_manila(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'portal:remind-missing-reports'));

        $this->assertNotNull($event);
        $this->assertSame('0 18 * * 1-5', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
    }

    private function reminders(): PortalReportReminders
    {
        return app(PortalReportReminders::class);
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
}
