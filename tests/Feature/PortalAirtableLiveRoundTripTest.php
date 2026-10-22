<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\AirtableClient;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Core\CoreLedger;
use App\Support\Portal\PortalBudgetLedger;
use App\Support\Portal\PortalCalendarEvents;
use App\Support\Portal\PortalManageUsers;
use App\Support\Portal\PortalReferences;
use App\Support\Portal\PortalScopeWork;
use Composer\CaBundle\CaBundle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    protected array $connectionsToTransact = ['core', 'portal', 'tracker'];

    private const TEST_BASE = 'app8DvnFZErPZT5Az';

    private const PROD_BASE = 'app8jGTzRDQt4yPIa';

    /** @var list<string> */
    private const TEST_BASES = [
        self::TEST_BASE,
        'appCoObE52yP1guFP', // Calendar
        'appSNMGACG48i3XFX', // Client Information
        'appYwnzEVZVXwH1hB', // Transaction Tracker
    ];

    /** @var list<string> */
    private const PROD_BASES = [
        self::PROD_BASE,
        'appxjrrZc99jJlpli', // Calendar
        'appLBcQ7leQQnJ7Pj', // Client Information
        'appp5MogesHN2eVvG', // Transaction Tracker
    ];

    /**
     * Every record a POST made, by base and table, so each is deleted again whatever the test does.
     *
     * @var list<array{base: string, table: string, id: string}>
     */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AIRTABLE_LIVE_WRITES') !== '1') {
            $this->markTestSkipped('Writes to the TEST Airtable base; run with AIRTABLE_LIVE_WRITES=1.');
        }
        // Never production: the profile and every active base must be the TEST ones.
        if (config('services.airtable.env') !== 'test') {
            $this->fail('Refused: AIRTABLE_ENV must be test for the live round trip.');
        }
        $base = (string) config('services.airtable.base');
        if ($base !== self::TEST_BASE) {
            $this->fail('Refused: AIRTABLE_BASE_ID must be the TEST base '.self::TEST_BASE.', not '.$base.'.');
        }
        foreach (['calendar', 'clients', 'tracker'] as $key) {
            $extra = (string) config('services.airtable.bases.'.$key);
            if ($extra !== '' && (in_array($extra, self::PROD_BASES, true) || ! in_array($extra, self::TEST_BASES, true))) {
                $this->fail('Refused: '.$key.' names '.$extra.', not a TEST base. Nothing was sent.');
            }
        }
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($event->request->method() !== 'POST' || ! $event->response->successful()) {
                return;
            }
            $path = (string) parse_url($event->request->url(), PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', $path)));
            $baseId = (string) ($segments[1] ?? '');
            $this->assertContains($baseId, self::TEST_BASES, 'A write left a TEST base: '.$path);
            $this->made[] = [
                'base' => self::baseKeyFor($baseId),
                'table' => rawurldecode((string) ($segments[2] ?? '')),
                'id' => (string) $event->response->json('id'),
            ];
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
                'report_date' => '2026-10-19', 'actual_work_date' => '2026-10-19', 'hours_rendered' => $hours, 'change_in_elements' => 1,
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
        $vacationEarn = $portal->table('earn_codes')->where('description', '01 Vacation Leave')->first(['id']);
        $this->assertNotNull($vacationEarn);
        $portal->table('requests_earn_codes')->insert(['request_id' => $leave, 'earn_code_id' => $vacationEarn->id]);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, $personId.':2026-10-21-leave', 'portal.requests', ['id' => (string) $leave], 'requests.leave', (string) $leave, $personId, null);
        $overtime = $this->request(['type' => 'Overtime', 'no_of_hours' => 2, 'request_date' => '2026-10-19']);
        $overtimeEarn = $portal->table('earn_codes')->where('description', '02 Regular Overtime')->first(['id']);
        $this->assertNotNull($overtimeEarn);
        $portal->table('projects_requests')->insert(['project_id' => $project->id, 'request_id' => $overtime]);
        $portal->table('requests_activity_codes')->insert(['request_id' => $overtime, 'activity_code_id' => $activity->id]);
        $portal->table('requests_earn_codes')->insert(['request_id' => $overtime, 'earn_code_id' => $overtimeEarn->id]);
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
            'requested_by' => $personId,
        ]);
        $portal->table('projects_clients')->insert(['project_id' => $newProject, 'client_id' => $client->id]);
        $portal->table('employees_projects')->insert(['project_id' => $newProject, 'employee_id' => $personId, 'role_on_project' => 'member']);
        $ledger->recordAdd(CoreLedger::PRODUCT_PORTAL, 'projects:'.$newProject, 'portal.projects', ['id' => (string) $newProject], 'projects', (string) $newProject, $personId, null);

        // One assigned scope with a reported figure: its own Project Scopes record, linked and fractioned.
        $task = $portal->table('project_scope_t4_tasks')->whereNotNull('airtable_record_id')->where('wbs_code', 'not like', '%-')->orderBy('id')->first(['wbs_code', 'airtable_record_id']);
        $scopeCode = trim((string) $task->wbs_code);
        $portal->table('projects_scope_assignees')->insert(['project_id' => $newProject, 'wbs_code' => $scopeCode, 'employee_id' => $personId]);
        $portal->table('projects_scope_progress')->insert(['project_id' => $newProject, 'wbs_code' => $scopeCode, 'employee_id' => $personId, 'report_date' => '2026-10-19', 'percent' => 40]);
        $ledger->within($portal, static fn () => app(PortalScopeWork::class)->reconcileProjects([$newProject], Employee::query()->findOrFail($personId)));

        app(PortalCalendarEvents::class)->create($actor, Request::create('/api/development/portal/calendar/events', 'POST', [
            'title' => 'SYNC TEST Event',
            'details' => 'Round trip event',
            'startsOn' => '2026-10-19',
            'startsAt' => '09:00',
            'endsOn' => '2026-10-19',
            'endsAt' => '10:00',
            'category' => 'company_event',
            'audience' => 'everyone',
        ]));

        // Finance writes the tracker itself: one transaction linked to an account and an envelope
        // already in the base.
        config(['products.catalog.transaction-tracker.enabled' => true]);
        $trackerAccount = DB::connection('tracker')->table('accounts')->whereNotNull('airtable_record_id')->where('active', true)->orderByDesc('id')->first();
        $trackerEnvelope = DB::connection('tracker')->table('envelopes_budget')->whereNotNull('airtable_record_id')->where('active', true)->orderByDesc('id')->first();
        $this->assertNotNull($trackerAccount, 'The tracker needs an open account with an Airtable id.');
        $this->assertNotNull($trackerEnvelope, 'The tracker needs an open envelope with an Airtable id.');
        $trackerTransaction = app(PortalBudgetLedger::class)->record(
            $actor,
            'SYNC TEST Transaction',
            PortalBudgetLedger::TYPE_EXPENSE,
            100,
            '2026-10-19',
            'Round trip transaction',
            ['accountId' => (int) $trackerAccount->id, 'envelopeId' => (int) $trackerEnvelope->id],
        );

        // Client Information is its own base; the Users & Projects Client copy is only for links.
        $clientCode = null;
        foreach (range(90, 99) as $number) {
            $code = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            if (! $portal->table('clients')->where('client_id', $code)->exists()) {
                $clientCode = $code;
                break;
            }
        }
        $this->assertNotNull($clientCode, 'Client Information needs a free two-digit code.');
        app(PortalReferences::class)->addClient($actor, Request::create('/api/development/portal/manage/references/clients', 'POST', [
            'name' => 'SYNC TEST Client',
            'code' => $clientCode,
        ]));
        $newClient = (int) $portal->table('clients')->where('name', 'SYNC TEST Client')->value('id');

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
        $this->assertSame('2026-10-19', $line['Actual Work Date']);
        $this->assertSame([$earnCode->airtable_record_id], $line['Earn Code']);
        $this->assertSame([$id('employees', $personId)], $line['Employee No.']);
        $this->assertSame([$project->airtable_record_id], $line['Project']);
        $leaveRecord = $this->read('Request', $id('requests', $leave));
        $this->assertSame('Vacation Leave', $leaveRecord['Category']);
        $this->assertSame([$portal->table('earn_codes')->where('id', $vacationEarn->id)->value('airtable_record_id')], $leaveRecord['Earn Code']);
        $overtimeRecord = $this->read('Request', $id('requests', $overtime));
        $this->assertSame([$project->airtable_record_id], $overtimeRecord['Project']);
        $this->assertSame([$activity->airtable_record_id], $overtimeRecord['Activity Scope']);
        $this->assertSame([$portal->table('earn_codes')->where('id', $overtimeEarn->id)->value('airtable_record_id')], $overtimeRecord['Earn Code']);
        $claimRecord = $this->read('Reimbursements', $id('reimbursements', $claim));
        $this->assertEquals(12.5, $claimRecord['Cost']);
        $this->assertSame('Angeles Pampanga Office', $claimRecord['Team']);
        $this->assertSame('sample.jpg', $claimRecord['Receipts (If Applicable)'][0]['filename']);
        $projectRecord = $this->read('Project/s', $id('projects', $newProject));
        $this->assertSame([$lead->airtable_record_id], $projectRecord['PM ID']);
        $this->assertSame([$client->airtable_record_id], $projectRecord['Client']);
        $this->assertSame([$id('employees', $personId)], $projectRecord['Project Members']);
        $this->assertSame([$id('employees', $personId)], $projectRecord['createdBy']);
        $this->assertSame([$id('employees', $personId)], $projectRecord['lastModifiedBy']);
        $this->assertNotEmpty($projectRecord['Activity Scope']);
        $this->assertTrue($projectRecord['Are the details in the project ledger (MS Teams Planner) updated?']);
        $scopeId = (int) $portal->table('projects_project_scopes')->where('project_id', $newProject)->value('project_scope_id');
        $this->assertGreaterThan(0, $scopeId);
        $scopeRecord = $this->read('Project Scopes', $id('project_scopes', $scopeId));
        $this->assertSame([$id('projects', $newProject)], $scopeRecord['Project']);
        $this->assertSame([$id('employees', $personId)], $scopeRecord['AssignedTo']);
        $this->assertSame([$task->airtable_record_id], $scopeRecord['Scope - T4 Tasks']);
        $this->assertSame(0.4, (float) $scopeRecord['Progress']);
        $this->assertSame([$id('employees', $personId)], $this->read('Warnings', $id('warnings', $warning))['Employee']);
        $eventRecord = collect(app(AirtableClient::class)->records('Events', 'calendar'))
            ->firstWhere(fn (array $record): bool => ($record['fields']['Name'] ?? null) === 'SYNC TEST Event');
        $this->assertNotNull($eventRecord);
        $this->assertSame('Round trip event', $eventRecord['fields']['Details']);
        $this->assertSame('Company Event', $eventRecord['fields']['Type']);
        $this->assertSame('Everyone', $eventRecord['fields']['Attendees']);
        $this->assertSame('2026-10-19 09:00:00', Carbon::parse($eventRecord['fields']['Start Date'])->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 10:00:00', Carbon::parse($eventRecord['fields']['End Date'])->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame(trim($actor->first_name.' '.$actor->last_name), $eventRecord['fields']['Author']);
        $trackerRecordId = (string) DB::connection('tracker')->table('transactions')->where('id', $trackerTransaction)->value('airtable_record_id');
        $transactionRecord = $this->read('Transactions', $trackerRecordId, 'tracker');
        $this->assertSame('SYNC TEST Transaction', $transactionRecord['Transaction Name']);
        $this->assertEquals(1.0, $transactionRecord['Amount']);
        $this->assertSame([$trackerAccount->airtable_record_id], $transactionRecord['Account']);
        $this->assertSame([$trackerEnvelope->airtable_record_id], $transactionRecord['Envelope']);
        $clientInfoId = (string) $portal->table('clients')->where('id', $newClient)->value('client_info_airtable_record_id');
        $this->assertStringStartsWith('rec', $clientInfoId);
        $this->assertSame('SYNC TEST Client', $this->read('Clients', $clientInfoId, 'clients')['Name']);

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
        DB::connection('tracker')->table('transactions')->where('id', $trackerTransaction)->update(['transaction_name' => 'SYNC TEST Transaction renamed']);
        app(PortalBudgetLedger::class)->trackerEdit('tracker.transactions', 'transactions', $trackerTransaction, $actor);
        app(PortalReferences::class)->updateClient($actor, Request::create('/api/development/portal/manage/references/clients/'.$newClient, 'PATCH', [
            'name' => 'SYNC TEST Client renamed',
            'code' => $clientCode,
        ]), $newClient);
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
        $this->assertSame('SYNC TEST Transaction renamed', $this->read('Transactions', $trackerRecordId, 'tracker')['Transaction Name']);
        $this->assertSame('SYNC TEST Client renamed', $this->read('Clients', $clientInfoId, 'clients')['Name']);

        // 3. Deletes: a cancelled request leaves Airtable, and so does a line an edit dropped.
        $overtimeRecord = $id('requests', $overtime);
        $portal->table('requests')->where('id', $overtime)->update(['status' => 'Cancelled']);
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.requests', ['id' => (string) $overtime], 'requests.overtime', (string) $overtime, null, $personId, null);
        $droppedRecord = $id('user_reports', $lines[1]);
        $portal->table('user_reports')->where('id', $lines[1])->delete();
        $ledger->recordEdit(CoreLedger::PRODUCT_PORTAL, 'portal.user_reports', ['id' => '2026-10-19-daily', 'lineIds' => [$lines[0]], 'removedAirtableIds' => [$droppedRecord]], 'reports.submitted', '2026-10-19-daily', $personId.':2026-10-19-daily', $personId, null);
        app(PortalReferences::class)->removeClient($actor, Request::create('/api/development/portal/manage/references/clients/'.$newClient, 'DELETE'), $newClient);
        $this->assertLanded($sync->run(true));
        $client = app(AirtableClient::class);
        $this->assertFalse($client->exists('Request', $overtimeRecord));
        $this->assertFalse($client->exists('User Reports', $droppedRecord));
        $this->assertFalse($client->exists('Clients', $clientInfoId, 'clients'));

        // 4. Nothing is left to send: a second run writes nothing.
        $this->assertSame([], array_filter($sync->run(true)['items'], static fn (array $item): bool => in_array($item['outcome'], ['create', 'update', 'delete', 'discard', 'failed', 'blocked'], true)));
        $this->assertNotNull(Action::query()->find($report)?->synced_at);

        // 5. Clean up and prove the TEST base holds none of them any more.
        $this->made = array_values(array_filter(
            $this->made,
            static fn (array $entry): bool => ! ($entry['base'] === 'clients' && $entry['id'] === $clientInfoId),
        ));
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
    private function read(string $table, string $recordId, string $base = 'main'): array
    {
        $this->assertStringStartsWith('rec', $recordId);
        $baseId = $base === 'main' ? self::TEST_BASE : (string) config('services.airtable.bases.'.$base);
        $token = $base === 'main' ? config('services.airtable.token') : config('services.airtable.tokens.'.$base, config('services.airtable.token'));
        $fields = Http::withToken((string) $token)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->get('https://api.airtable.com/v0/'.$baseId.'/'.rawurlencode($table).'/'.$recordId)
            ->throw()
            ->json('fields');

        return is_array($fields) ? $fields : [];
    }

    private static function baseKeyFor(string $baseId): string
    {
        return match ($baseId) {
            'appCoObE52yP1guFP' => 'calendar',
            'appSNMGACG48i3XFX' => 'clients',
            'appYwnzEVZVXwH1hB' => 'tracker',
            default => 'main',
        };
    }

    private function deleteMade(): void
    {
        $client = app(AirtableClient::class);
        foreach ($this->made as $entry) {
            try {
                $client->delete($entry['table'], $entry['id'], $entry['base']);
            } catch (Throwable $error) {
                fwrite(STDERR, 'Could not delete '.$entry['table'].' '.$entry['id'].': '.$error->getMessage().PHP_EOL);
            }
        }
        $this->made = [];
    }
}
