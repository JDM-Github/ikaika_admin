<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class PortalAuditTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    public function test_records_a_member_action_without_secrets(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);

        $id = app(PortalAudit::class)->log(
            (int) $employee->id,
            'create',
            'reports.submitted',
            '2026-08-21-daily',
            'Filed a daily report',
            [
                'kind' => 'daily',
                'token' => 'must-not-persist',
                'password' => 'must-not-persist',
            ],
            '127.0.0.1',
            'PortalTest/1.0',
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame((int) $employee->id, $row->employee_id);
        $this->assertSame('create', $row->action);
        $this->assertSame('reports.submitted', $row->resource);
        $this->assertSame('2026-08-21-daily', $row->record_id);
        $this->assertSame('Filed a daily report', $row->message);
        $this->assertEquals(['kind' => 'daily'], $row->payload);
        $this->assertSame('127.0.0.1', $row->ip_address);
    }

    public function test_stores_a_notification_the_ui_can_open_later(): void
    {
        $recipient = Employee::query()->orderBy('id')->first();
        $actor = Employee::query()->orderByDesc('id')->first();
        $this->assertNotNull($recipient);
        $this->assertNotNull($actor);

        $id = app(PortalAudit::class)->notify(
            (int) $recipient->id,
            'requests.leave.approved',
            'Leave approved',
            'Your sick leave on 9 Sep 2026 was approved.',
            '/requests/leave',
            'Open request',
            ['requestId' => 41, 'screen' => 'leaveRequest'],
            (int) $actor->id,
        );

        $row = PortalNotification::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame((int) $recipient->id, $row->employee_id);
        $this->assertSame((int) $actor->id, $row->actor_id);
        $this->assertSame('requests.leave.approved', $row->type);
        $this->assertSame('Leave approved', $row->title);
        $this->assertSame('Your sick leave on 9 Sep 2026 was approved.', $row->message);
        $this->assertSame('/requests/leave', $row->link_path);
        $this->assertSame('Open request', $row->link_label);
        $this->assertEquals(['requestId' => 41, 'screen' => 'leaveRequest'], $row->payload);
        $this->assertNull($row->read_at);
    }

    public function test_marks_a_notification_read_only_for_its_owner(): void
    {
        $owner = Employee::query()->orderBy('id')->first();
        $other = Employee::query()->orderByDesc('id')->first();
        $this->assertNotNull($owner);
        $this->assertNotNull($other);
        $this->assertNotSame((int) $owner->id, (int) $other->id);

        $audit = app(PortalAudit::class);
        $id = $audit->notify(
            (int) $owner->id,
            'system',
            'Welcome',
            'Your portal account is ready.',
        );

        $this->assertFalse($audit->markRead($id, (int) $other->id));
        $this->assertNull(PortalNotification::query()->find($id)?->read_at);

        $this->assertTrue($audit->markRead($id, (int) $owner->id));
        $this->assertNotNull(PortalNotification::query()->find($id)?->read_at);
        $this->assertTrue($audit->markRead($id, (int) $owner->id));
    }

    public function test_rejects_a_blank_notification(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);

        $this->expectException(InvalidArgumentException::class);
        app(PortalAudit::class)->notify((int) $employee->id, '', 'Title', 'Body');
    }

    public function test_formats_activity_dates_as_weekday_month_day_year(): void
    {
        $this->assertSame(
            'Monday, September 7, 2026',
            PortalActivityCopy::date('2026-09-07'),
        );
    }

    public function test_writes_a_decision_into_both_the_requester_and_the_approver_streams(): void
    {
        $requester = Employee::query()->orderBy('id')->first();
        $approver = Employee::query()->orderByDesc('id')->first();
        $this->assertNotNull($requester);
        $this->assertNotNull($approver);
        $this->assertNotSame((int) $requester->id, (int) $approver->id);

        $requesterName = trim(trim((string) $requester->first_name).' '.trim((string) $requester->last_name));
        $approverName = trim(trim((string) $approver->first_name).' '.trim((string) $approver->last_name));
        if ($requesterName === '') {
            $requesterName = 'Member';
        }
        if ($approverName === '') {
            $approverName = 'Member';
        }

        app(PortalAudit::class)->recordDecision(
            $requester,
            $approver,
            'requests.leave',
            'Sick Leave request for Monday, September 7, 2026',
            true,
            '41',
        );

        $requesterLog = PortalLog::query()
            ->where('employee_id', $requester->id)
            ->where('resource', 'requests.leave')
            ->where('record_id', '41')
            ->orderByDesc('id')
            ->first();
        $approverLog = PortalLog::query()
            ->where('employee_id', $approver->id)
            ->where('resource', 'requests.leave')
            ->where('record_id', '41')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($requesterLog);
        $this->assertNotNull($approverLog);
        $this->assertSame('PATCH', $requesterLog->action);
        $this->assertSame(
            "{UserName|Your} Sick Leave request for Monday, September 7, 2026 has been approved by {$approverName}",
            $requesterLog->message,
        );
        $this->assertSame(
            "{UserName|You} approved {$requesterName}'s Sick Leave request for Monday, September 7, 2026",
            $approverLog->message,
        );
    }
}
