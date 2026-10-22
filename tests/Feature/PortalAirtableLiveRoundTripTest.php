<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\AirtableClient;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Core\CoreLedger;
use App\Support\Portal\PortalManageUsers;
use Composer\CaBundle\CaBundle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * A real round trip against the TEST Airtable base (2026-10-20, user: "TEST only, never the production").
 * Opt-in with AIRTABLE_LIVE_WRITES=1 and refused for any base but the TEST one. The sync creates, updates
 * and deletes records named SYNC TEST, each is read back from Airtable, and every record it made is
 * deleted again; the SQL side rolls back with the test.
 */
class PortalAirtableLiveRoundTripTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    private const TEST_BASE = 'app8DvnFZErPZT5Az';

    private const PROD_BASE = 'app8jGTzRDQt4yPIa';

    /**
     * Every record a POST made, by table, so each is deleted again whatever the test does.
     *
     * @var array<string, list<string>>
     */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AIRTABLE_LIVE_WRITES') !== '1') {
            $this->markTestSkipped('Writes to the TEST Airtable base; run with AIRTABLE_LIVE_WRITES=1.');
        }
        // Never production: the PROD base is refused outright, and nothing but the TEST base is accepted.
        $base = (string) config('services.airtable.base');
        if ($base === self::PROD_BASE || $base !== self::TEST_BASE) {
            $this->fail('Refused: AIRTABLE_BASE_ID must be the TEST base '.self::TEST_BASE.', not '.$base.'.');
        }
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($event->request->method() !== 'POST' || ! $event->response->successful()) {
                return;
            }
            $path = (string) parse_url($event->request->url(), PHP_URL_PATH);
            $this->assertStringStartsWith('/v0/'.self::TEST_BASE.'/', $path);
            $this->made[rawurldecode(substr($path, strlen('/v0/'.self::TEST_BASE.'/')))][] = (string) $event->response->json('id');
        });
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteMade();
        } finally {
            parent::tearDown();
        }
    }

    public function test_creates_updates_and_deletes_land_in_the_test_base(): void
    {
        $sync = app(PortalAirtableSync::class);
        $ledger = app(CoreLedger::class);
        $portal = DB::connection('portal');
        $this->assertNull($sync->wrongBase());

        $lead = $this->existing('employees', 'Employees', 'email');
        $project = $this->existing('projects', 'Project/s');
        $activity = $this->existing('activity_codes', 'Activity Code/s');
        $earnCode = $this->existing('earn_codes', 'Earn Codes');
        $client = $this->existing('clients', 'Clients');
        $actor = Employee::query()->findOrFail($lead->id);

        // A new person, and in the same batch everything that links them: they must be created first.
        $person = app(PortalManageUsers::class)->createEmployee($actor, [
            'id_no' => '991231-9099', 'first_name' => 'SYNC TEST', 'last_name' => 'Person', 'email' => 'sync.test.person@ikaikabim.test',
            'status' => 'Active', 'employment_status' => 'Probation', 'role' => 'User', 'department' => 'MIS',
            'location' => 'Laguna', 'job_title' => 'Software Engineer', 'start_date' => '2026-10-20', 'phone_number' => '09170000000',
        ]);
        $personId = (int) $person->getKey();

        $lines = [];
        foreach ([4, 2] as $hours) {
            $line = (int) $portal->table('user_reports')->insertGetId([
                'report_date' => '2026-10-19', 'hours_rendered' => $hours, 'change_in_elements' => 1,
                'remarks' => 'SYNC TEST line', 'late_submission' => 'NO', 'late_submission_approval' => 'N/A', 'approval' => 'Pending',
            ]);
            $portal->table('employees_user_reports')->insert(['employee_id' => $personId, 'user_report_id' => $line]);
            $portal->table('projects_user_reports')->insert(['project_id' => $project->id, 'user_report_id' => $line]);
            $portal->table('user_reports_activity_codes')->insert(['user_report_id' => $line, 'activity_code_id' => $activity->id]);
            $portal->table('user_reports_earn_codes')->insert(['user_report_id' => $line, 'earn_code_id' => $earnCode->id]);
            $lines[] = $line;
        }
        $report = $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $personId.':2026-10-19-daily', 'portal.user_reports', ['id' => '2026-10-19-daily', 'lineIds' => $lines], 'reports.submitted', '2026-10-19-daily', $personId, null);

        $leave = $this->request(['type' => 'Leave', 'category' => '01 Vacation Leave', 'request_date' => '2026-10-21']);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $personId.':2026-10-21-leave', 'portal.requests', ['id' => (string) $leave], 'requests.leave', (string) $leave, $personId, null);
        $overtime = $this->request(['type' => 'Overtime', 'no_of_hours' => 2, 'request_date' => '2026-10-19']);
        $portal->table('projects_requests')->insert(['project_id' => $project->id, 'request_id' => $overtime]);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $personId.':2026-10-19-overtime', 'portal.requests', ['id' => (string) $overtime], 'requests.overtime', (string) $overtime, $personId, null);

        $claim = (int) $portal->table('reimbursements')->insertGetId([
            'reimb_date' => '2026-10-19', 'item' => 'SYNC TEST', 'cost' => 12.5, 'qty' => 2, 'purpose' => 'Round trip check',
            'employee_name_input' => 'SYNC TEST Person', 'team' => '01 Angeles Pampanga Office', 'status' => 'Pending',
        ]);
        $portal->table('employees_reimbursements')->insert(['employee_id' => $personId, 'reimbursement_id' => $claim]);
        $portal->table('attachments')->insert([
            'table_name' => 'reimbursements', 'record_id' => $claim, 'field_name' => 'receipts',
            'file_url' => 'https://res.cloudinary.com/demo/image/upload/sample.jpg', 'file_name' => 'sample.jpg',
        ]);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $personId.':claim', 'portal.reimbursements', ['id' => (string) $claim, 'receipt' => true], 'requests.reimbursement', (string) $claim, $personId, null);

        $newProject = (int) $portal->table('projects')->insertGetId([
            'project_number' => '999999', 'project_name' => 'SYNC TEST Project', 'department' => 'MIS', 'type_of_job' => 'Software Development',
            'status' => 'Started', 'area_sqft' => 120.5, 'project_lead_email' => $lead->email, 'project_creation_status' => 'Approved',
            'msteams_project_link' => 'https://teams.microsoft.com/sync-test', 'is_ledger_details_updated' => 1,
        ]);
        $portal->table('projects_clients')->insert(['project_id' => $newProject, 'client_id' => $client->id]);
        $portal->table('employees_projects')->insert(['project_id' => $newProject, 'employee_id' => $personId, 'role_on_project' => 'member']);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'projects:'.$newProject, 'portal.projects', ['id' => (string) $newProject], 'projects', (string) $newProject, $personId, null);

        $warning = (int) $portal->table('warnings')->insertGetId([
            'warning_date' => '2026-10-19', 'description' => 'SYNC TEST warning', 'status' => 'Active', 'violation_type' => 'Report',
        ]);
        $portal->table('employees_warnings')->insert(['employee_id' => $personId, 'warning_id' => $warning]);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'warnings:'.$warning, 'portal.warnings', ['id' => (string) $warning], 'warnings', (string) $warning, $personId, null);

        // 1. Every create lands, its id is written back, and Airtable holds what was sent.
        $this->assertLanded($sync->run(true));
        $id = fn (string $table, int $row): string => (string) $portal->table($table)->where('id', $row)->value('airtable_record_id');
        $personRecord = $this->read('Employees', $id('employees', $personId));
        $this->assertSame('SYNC TEST', $personRecord['First Name']);
        $this->assertSame('Laguna', $personRecord['Location']);
        $line = $this->read('User Reports', $id('user_reports', $lines[0]));
        $this->assertSame(4, (int) $line['Hours Rendered']);
        $this->assertSame('2026-10-19', $line['Date']);
        $this->assertSame([$id('employees', $personId)], $line['Employee No.']);
        $this->assertSame([$project->airtable_record_id], $line['Project']);
        $this->assertSame('Vacation Leave', $this->read('Request', $id('requests', $leave))['Category']);
        $this->assertSame([$project->airtable_record_id], $this->read('Request', $id('requests', $overtime))['Project']);
        $claimRecord = $this->read('Reimbursements', $id('reimbursements', $claim));
        $this->assertEquals(12.5, $claimRecord['Cost']);
        $this->assertSame('Angeles Pampanga Office', $claimRecord['Team']);
        $this->assertSame('sample.jpg', $claimRecord['Receipts (If Applicable)'][0]['filename']);
        $projectRecord = $this->read('Project/s', $id('projects', $newProject));
        $this->assertSame([$lead->airtable_record_id], $projectRecord['PM ID']);
        $this->assertSame([$client->airtable_record_id], $projectRecord['Client']);
        $this->assertSame([$id('employees', $personId)], $projectRecord['Project Members']);
        $this->assertTrue($projectRecord['Are the details in the project ledger (MS Teams Planner) updated?']);
        $this->assertSame([$id('employees', $personId)], $this->read('Warnings', $id('warnings', $warning))['Employee']);

        // 2. Updates: a decision, an edited line, a renamed project, a changed phone, an approved claim.
        $portal->table('requests')->where('id', $leave)->update(['status' => 'Approved', 'approver_remarks' => 'SYNC TEST ok']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $leave], 'requests.leave', (string) $leave, null, $personId, null);
        $portal->table('user_reports')->where('id', $lines[0])->update(['hours_rendered' => 5]);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports', ['id' => '2026-10-19-daily', 'lineIds' => $lines], 'reports.submitted', '2026-10-19-daily', $personId.':2026-10-19-daily', $personId, null);
        $portal->table('projects')->where('id', $newProject)->update(['project_name' => 'SYNC TEST Project renamed']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.projects', ['fields' => ['project_name' => 'SYNC TEST Project renamed']], 'projects', (string) $newProject, null, $personId, null);
        $portal->table('employees')->where('id', $personId)->update(['phone_number' => '09171111111']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.employees', ['columns' => ['phone_number']], 'employees', (string) $personId, null, $personId, null);
        $portal->table('reimbursements')->where('id', $claim)->update(['status' => 'Approved']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.reimbursements', ['id' => (string) $claim, 'receipt' => true], 'requests.reimbursement', (string) $claim, null, $personId, null);
        $this->assertLanded($sync->run(true));
        $leaveRecord = $this->read('Request', $id('requests', $leave));
        $this->assertSame('Approved', $leaveRecord['Status']);
        $this->assertSame('SYNC TEST ok', $leaveRecord['Approver Remarks']);
        $this->assertSame(5, (int) $this->read('User Reports', $id('user_reports', $lines[0]))['Hours Rendered']);
        $renamed = $this->read('Project/s', $id('projects', $newProject));
        $this->assertSame('SYNC TEST Project renamed', trim((string) $renamed['Project Name']));
        $this->assertSame([$client->airtable_record_id], $renamed['Client']);
        $this->assertSame('09171111111', $this->read('Employees', $id('employees', $personId))['Phone Number']);
        $this->assertSame('Completed', $this->read('Reimbursements', $id('reimbursements', $claim))['Status']);

        // 3. Deletes: a cancelled request leaves Airtable, and so does a line an edit dropped.
        $overtimeRecord = $id('requests', $overtime);
        $portal->table('requests')->where('id', $overtime)->update(['status' => 'Cancelled']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $overtime], 'requests.overtime', (string) $overtime, null, $personId, null);
        $droppedRecord = $id('user_reports', $lines[1]);
        $portal->table('user_reports')->where('id', $lines[1])->delete();
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports', ['id' => '2026-10-19-daily', 'lineIds' => [$lines[0]], 'removedAirtableIds' => [$droppedRecord]], 'reports.submitted', '2026-10-19-daily', $personId.':2026-10-19-daily', $personId, null);
        $this->assertLanded($sync->run(true));
        $client = app(AirtableClient::class);
        $this->assertFalse($client->exists('Request', $overtimeRecord));
        $this->assertFalse($client->exists('User Reports', $droppedRecord));

        // 4. Nothing is left to send: a second run writes nothing.
        $this->assertSame([], array_filter($sync->run(true)['items'], static fn (array $item): bool => in_array($item['outcome'], ['create', 'update', 'delete', 'discard', 'failed', 'blocked'], true)));
        $this->assertNotNull(Action::query()->find($report)?->synced_at);

        // 5. Clean up and prove the TEST base holds none of them any more.
        $this->deleteMade();
        foreach ([$id('employees', $personId) => 'Employees', $id('user_reports', $lines[0]) => 'User Reports', $id('requests', $leave) => 'Request', $id('reimbursements', $claim) => 'Reimbursements', $id('projects', $newProject) => 'Project/s', $id('warnings', $warning) => 'Warnings'] as $record => $table) {
            $this->assertFalse($client->exists($table, (string) $record), $table.' '.$record.' was left in the TEST base.');
        }
    }

    /**
     * @param  array{items: list<array{outcome: string, resource: ?string, recordId: ?string, problems: list<string>}>}  $report
     */
    private function assertLanded(array $report): void
    {
        $stuck = array_filter($report['items'], static fn (array $item): bool => in_array($item['outcome'], ['failed', 'blocked', 'skipped'], true));
        $this->assertSame([], array_values(array_map(static fn (array $item): string => $item['resource'].' '.$item['recordId'].' '.$item['outcome'].': '.implode(' | ', $item['problems']), $stuck)));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function request(array $values): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'name' => 'SYNC TEST', 'reason' => 'Round trip check', 'status' => 'Pending', ...$values,
        ]);
    }

    // A row whose Airtable record the TEST base really holds, so links point at something there.
    private function existing(string $table, string $airtableTable, ?string $needs = null): object
    {
        $client = app(AirtableClient::class);
        $rows = DB::connection('portal')->table($table)->whereNotNull('airtable_record_id')
            ->when($needs !== null, fn ($query) => $query->whereNotNull($needs)->where($needs, '!=', ''))
            ->orderByDesc('id')->limit(10)->get();
        foreach ($rows as $row) {
            if ($client->exists($airtableTable, (string) $row->airtable_record_id)) {
                return $row;
            }
        }
        $this->fail('No '.$table.' row whose record the TEST base holds.');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $table, string $recordId): array
    {
        $this->assertStringStartsWith('rec', $recordId);
        $fields = Http::withToken((string) config('services.airtable.token'))
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->get('https://api.airtable.com/v0/'.self::TEST_BASE.'/'.rawurlencode($table).'/'.$recordId)
            ->throw()
            ->json('fields');

        return is_array($fields) ? $fields : [];
    }

    private function deleteMade(): void
    {
        $client = app(AirtableClient::class);
        foreach ($this->made as $table => $ids) {
            foreach ($ids as $recordId) {
                try {
                    $client->delete($table, $recordId);
                } catch (Throwable $error) {
                    fwrite(STDERR, 'Could not delete '.$table.' '.$recordId.': '.$error->getMessage().PHP_EOL);
                }
            }
        }
        $this->made = [];
    }
}
