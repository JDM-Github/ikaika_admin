<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Airtable\AirtableSyncMap;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Portal\PortalProjectApproval;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Carbon\CarbonInterval;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\FilesPortalReports;
use Tests\TestCase;

/**
 * End-to-end Airtable sync scenarios (2026-10-20): real portal endpoints write the ledger, the sync
 * replays it into a stateful fake base that answers like Airtable with typecast off, and each test
 * judges what the base holds afterwards, the ids written back to SQL and the actions' synced_at.
 */
class PortalAirtableSyncScenariosTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    private const REPORTS = '/api/development/portal/reports/submitted';

    private const LEAVE = '/api/development/portal/requests/leave';

    private const REIMBURSEMENT = '/api/development/portal/requests/reimbursement';

    // Holds the profile-change review power personally (migration 2026_10_14_060146).
    private const HEAD_ADMIN = '220202-0002';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    /**
     * The mapped fields of every table the scenarios touch, typed as the PROD base types them
     * (2026-10-20): a string is the field type, a list a single select's options, '@Table' a link.
     */
    private const BASE = [
        'Request' => [
            'Date' => 'date', 'Name' => 'singleLineText', 'Reason' => 'singleLineText', 'Status' => ['Approved', 'Pending', 'Rejected'],
            'Type' => 'singleLineText', 'Approver Remarks' => 'multilineText',
            'Category' => ['Regular Overtime', 'Rest Day Overtime', 'Holiday Overtime', 'Special Holiday Overtime', 'Sick Leave', 'Vacation Leave'],
            'No. of Hours' => 'number', 'Project' => '@Project/s', 'Original Work Day' => 'date', 'Offset Work Day' => 'date', 'Offset Hrs' => 'number',
        ],
        'Reimbursements' => [
            'Date' => 'date', 'Item' => 'singleLineText', 'Cost' => 'currency', 'QTY' => 'number', 'Purpose' => 'multilineText',
            'Employee Name (Input your name so you can filter the IDs in "Reimburse To" field)' => 'singleLineText',
            'Team' => ['Angeles Pampanga Office', 'Cebu Office'], 'Status' => ['Completed', 'Pending', 'Rejected'],
            'Approver Remarks' => 'multilineText', 'Reimburse To' => '@Employees',
            'Receipts (If Applicable)' => 'multipleAttachments', 'Reimbursement Receipt' => 'multipleAttachments',
        ],
        'User Reports' => [
            'Date' => 'date', 'Hours Rendered' => 'number', 'Change in Elements (+/-)' => 'number', 'Remarks' => 'singleLineText',
            'LateSubmission' => ['YES', 'NO'], 'LateSubmission Approval' => ['Approved', 'Pending', 'N/A', 'Rejected'],
            'Approval' => ['Approved', 'Rejected', 'Pending'], 'Employee No.' => '@Employees', 'Project' => '@Project/s',
            'Activity' => '@Activity Code/s', 'Earn Code' => '@Earn Codes', 'Approver Remarks' => 'multilineText',
            'Actual Work Date' => 'date', 'IsSupport' => ['Y', 'N'], 'End date' => 'date', 'Duration' => 'number',
        ],
        'Employees' => [
            'ID No.' => 'singleLineText', 'First Name' => 'singleLineText', 'Last Name' => 'singleLineText', 'Middle Name' => 'singleLineText',
            'Nickname' => 'multilineText', 'Email' => 'singleLineText',
            'Job Title' => ['Mechanical Engineer Manager', 'Software Engineer Manager', 'Mechanical Engineer', 'Software Engineer', 'President', 'CEO', 'BIM Modeler (Electrical)', 'BIM Modeler (Mechanical)', 'BIM Modeler (Structural)', 'BIM Modeler (Civil)', 'BIM Lead'],
            'Status' => ['Active', 'Inactive'], 'Employment Status' => ['Regular', 'Probation', 'Contractual', 'Terminated', 'AWOL', 'Resigned'],
            'Role' => ['Admin', 'User', 'ProjectAdmin'], 'Role Level' => ['Executive', 'Management', 'Project Leadership', 'Production/Technical', 'Support'],
            'Department' => ['Engineering', 'MIS'], 'Location' => ['Pampanga', 'Cebu', 'Laguna', 'USA', 'NCR'],
            'Start Date' => 'date', 'Date of Birth' => 'date', 'Blood Type' => ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-'],
            'Address' => 'singleLineText', 'Personal E-Mail' => 'singleLineText', 'Phone Number' => 'phoneNumber',
            'Tax Identification No.' => 'singleLineText', 'SSS No.' => 'singleLineText', 'PhilHealth No.' => 'singleLineText', 'HDMF No.' => 'singleLineText',
            'Bank Name' => ['Bank of the Philippine Islands', 'Banco De Oro', 'Metro Bank', 'Rizal Commercial Banking Corporation', 'China Bank', 'GoTyme Bank', 'SeaBank', 'Sterling Bank of Asia', 'Unionbank', 'Security Bank', 'Philippine National Bank', 'Land Bank of the Philippines', 'UNObank'],
            'Bank Account Number' => 'singleLineText', 'Bank SWIFT Code' => 'singleLineText',
            'Emergency Contact Person Name' => 'singleLineText', 'Emergency Contact Number' => 'phoneNumber', 'Emergency Contact Address' => 'multilineText',
            'Photo' => 'multipleAttachments', 'Tax Identification' => 'multipleAttachments', 'SS ID' => 'multipleAttachments',
            'PhilHealth ID' => 'multipleAttachments', 'HDMF ID' => 'multipleAttachments', 'National ID' => 'multipleAttachments',
            'Bank Certificate' => 'multipleAttachments',
        ],
        'Project/s' => [
            'Project Name' => 'multilineText', 'Department' => ['Engineering', 'MIS', 'Admin', 'General'],
            'Type of Job' => ['Scan-to-BIM', 'CAD-to-BIM', 'Dusty CAD', 'PDF-to-CAD', '6D', 'Material TakeOff', 'Clash Detection', 'Software Development', 'Management', 'Training'],
            'Area (in S.F.)' => 'number', 'Status' => ['Started', 'Done', 'Closed'], 'Project Lead Email' => 'singleLineText',
            'LOD' => self::LODS, 'AR LOD' => self::LODS, 'ST LOD' => self::LODS, 'MD LOD' => self::LODS, 'EL LOD' => self::LODS, 'PL LOD' => self::LODS, 'FP LOD' => self::LODS,
            'Forma Link' => 'multilineText', 'MSTeams Project Link' => 'url', 'MS Planner Link' => 'url', 'Panoramic Link' => 'url', 'MS Loop Link' => 'url',
            'Google Drive Folder Path' => 'singleLineText', 'ProjectCreationStatus' => ['Approved', 'Rejected', 'Pending'],
            'ProjectCreationApprover' => 'singleLineText', 'Project Approver Email' => 'singleLineText', 'ProjectCreationApproverRemarks' => 'multilineText',
            'Is the ACC project renamed with ACC latest project number?' => 'checkbox',
            'Is the project ledger (MS Teams Planner) moved and updated to For Submission (completed status)?' => 'checkbox',
            'Are the details in the project ledger (MS Teams Planner) updated?' => 'checkbox',
            'PM ID' => '@Employees', 'Client' => '@Clients', 'Project Members' => '@Employees', 'Support Members' => '@Employees',
        ],
        'New Employee Data' => [
            'ID No.' => 'singleLineText', 'First Name' => 'singleLineText', 'Middle Name' => 'singleLineText', 'Last Name' => 'singleLineText',
            'Nickname' => 'multilineText', 'Email' => 'singleLineText',
            'Job Title' => ['Mechanical Engineer Manager', 'Software Engineer Manager', 'Mechanical Engineer', 'Software Engineer', 'President', 'CEO', 'BIM Modeler (Electrical)', 'BIM Modeler (Mechanical)', 'BIM Modeler (Structural)', 'BIM Modeler (Civil)'],
            'Start Date' => 'date', 'Status' => ['Active', 'Inactive'], 'Date of Birth' => 'date', 'Address' => 'singleLineText',
            'Personal E-Mail' => 'singleLineText', 'Phone Number' => 'phoneNumber', 'Tax Identification No.' => 'singleLineText',
            'PhilHealth No.' => 'singleLineText', 'SSS No.' => 'singleLineText', 'HDMF No.' => 'singleLineText', 'Role' => ['Admin', 'User'],
            'Department' => ['Engineering', 'MIS'], 'Emergency Contact Person Name' => 'singleLineText', 'Emergency Contact Number' => 'phoneNumber',
            'Emergency Contact Address' => 'multilineText',
            'Bank Name' => ['Bank of the Philippine Islands', 'Banco De Oro', 'Metro Bank', 'Rizal Commercial Banking Corporation', 'China Bank', 'GoTyme Bank', 'SeaBank', 'Sterling Bank of Asia', 'Unionbank', 'Security Bank', 'Philippine National Bank', 'Land Bank of the Philippines', 'UNObank'],
            'Bank Account Number' => 'singleLineText', 'Bank SWIFT Code' => 'singleLineText', 'Data Approval' => ['Approved', 'Reject', 'Pending'],
        ],
        'Activity Code/s' => ['Name' => 'singleLineText', 'IdNo' => 'singleLineText', 'Department' => ['Admin', 'MIS', 'Engineering', 'General', 'HR']],
        'Earn Codes' => ['Description' => 'multilineText', 'Did Not Work' => 'number', 'Did Work' => 'number'],
        'Clients' => ['Name' => 'singleLineText', 'ClientID' => 'singleLineText', 'Notes' => 'multilineText', 'Status' => ['Todo', 'In progress', 'Done']],
        'Warnings' => ['Date' => 'date', 'Description' => 'richText', 'Status' => ['Active', 'Inactive'], 'Violation Type' => ['N/A', 'Attendance', 'Report'], 'Employee' => '@Employees'],
        'Project Action History' => ['ActionName' => 'singleLineText', 'CreatedBy' => 'singleLineText', 'Remarks' => 'singleLineText', 'Project' => '@Project/s'],
    ];

    private const LODS = ['N/A', 'LOD 200', 'LOD 250', 'LOD 300', 'LOD 350', 'LOD 400', 'LOD 500'];

    /** @var array<string, array<string, array<string, mixed>>> The fake base: table => record id => fields. */
    private array $base = [];

    /** @var list<array{0: string, 1: string, 2: ?string, 3: array<string, mixed>}> Every request the base received: method, table, record, fields. */
    private array $log = [];

    /** @var list<array{0: string, 1: int|string}> Failures, each answering the next request of its method; 'lost' drops the connection. */
    private array $faults = [];

    // Runs after a create landed, before its answer goes back: the place a concurrent writer strikes.
    private ?Closure $afterCreate = null;

    private int $minted = 0;

    private int $uploads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.airtable.token' => 'test-token',
            'services.airtable.base' => 'appTESTBASE000000',
            // With the tracker off an approval charges no budget envelope; that is PortalBudgetEnvelopesTest's.
            'products.catalog.transaction-tracker.enabled' => false,
        ]);
        // Earlier unsynced rows in the local ledger must not leak into these assertions.
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        // The client's spacing and its 429 lockout go through Sleep, so a scenario never waits for real.
        Sleep::fake();
    }

    // A filing is one record per line; an edit patches the line it kept, creates the one it added and
    // deletes the one it dropped; a delete removes the rest; and a second send has nothing left to say.
    public function test_a_daily_report_follows_its_lines_through_filing_editing_and_deleting(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $headers = $this->signIn($member);
        $date = $this->freeReportDate($member);
        $project = $this->reportEntries($member)[0]['projectLabel'];
        [$kept, $dropped, $added] = DB::connection('portal')->table('activity_codes')
            ->whereNotNull('name')->where('name', '!=', '')->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')->limit(3)->get()->all();
        $entry = static fn (object $activity, float $hours): array => [
            'projectLabel' => $project,
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel($activity->name, $activity->id_no),
            'hoursRendered' => $hours,
            'elementChange' => 1,
        ];

        $this->postJson(self::REPORTS, ['kind' => 'daily', 'reports' => [[
            'reportDate' => $date, 'remarks' => 'Two lines', 'entries' => [$entry($kept, 3), $entry($dropped, 5)],
        ]]], $headers)->assertCreated();
        $filed = $this->lastAction('reports.submitted', $date.'-daily');
        [$keptLine, $droppedLine] = $filed->parameters['parameters']['lineIds'];
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame(2, $this->writes('POST'));
        $keptRecord = $this->airtableId('user_reports', $keptLine);
        $droppedRecord = $this->airtableId('user_reports', $droppedLine);
        $first = $this->record('User Reports', $keptRecord);
        $this->assertSame($date, $first['Date']);
        $this->assertEquals(3, $first['Hours Rendered']);
        $this->assertSame([$member->airtable_record_id], $first['Employee No.']);
        $this->assertSame([$this->linkedRecord('projects_user_reports', 'project_id', 'projects', $keptLine)], $first['Project']);
        $this->assertSame([$kept->airtable_record_id], $first['Activity']);
        $this->assertSame([$this->linkedRecord('user_reports_earn_codes', 'earn_code_id', 'earn_codes', $keptLine)], $first['Earn Code']);
        $this->assertSame([$dropped->airtable_record_id], $this->record('User Reports', $droppedRecord)['Activity']);

        // The first line's hours change, the second line goes, a third comes.
        $this->patchJson(self::REPORTS.'/'.$date.'-daily', ['entries' => [$entry($kept, 4), $entry($added, 2)], 'remarks' => 'Edited'], $headers)->assertOk();
        $edited = $this->lastAction('reports.submitted', $date.'-daily');
        [$keptAgain, $addedLine] = $edited->parameters['parameters']['lineIds'];
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame([3, 1, 1], [$this->writes('POST'), $this->writes('PATCH'), $this->writes('DELETE')]);
        $this->assertSame($keptRecord, $this->airtableId('user_reports', $keptAgain));
        $this->assertEquals(4, $this->record('User Reports', $keptRecord)['Hours Rendered']);
        $this->assertSame('Edited', $this->record('User Reports', $keptRecord)['Remarks']);
        $this->assertArrayNotHasKey((string) $droppedRecord, $this->base['User Reports']);
        $addedRecord = $this->airtableId('user_reports', $addedLine);
        $this->assertNotSame($keptRecord, $addedRecord);
        $this->assertSame([$added->airtable_record_id], $this->record('User Reports', $addedRecord)['Activity']);
        $this->assertEquals(2, $this->record('User Reports', $addedRecord)['Hours Rendered']);

        $this->deleteJson(self::REPORTS.'/'.$date.'-daily', [], $headers)->assertNoContent();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertArrayNotHasKey((string) $keptRecord, $this->base['User Reports']);
        $this->assertArrayNotHasKey((string) $addedRecord, $this->base['User Reports']);

        // Everything landed, so a second send has nothing to plan and writes nothing.
        $writes = $this->writes();
        $this->assertSame([], app(PortalAirtableSync::class)->run(true)['items']);
        $this->assertSame($writes, $this->writes());
    }

    // Filed, edited and cancelled in the portal, a leave is created, patched and removed in Airtable;
    // a kind the base does not offer still lands, without its Category.
    public function test_a_leave_request_is_created_patched_and_deleted_once_cancelled(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $headers = $this->signIn($member);
        $day = $this->freeRequestDay($member);
        $requestId = $this->fileLeave($headers, $day, '01 Vacation Leave');
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $recordId = $this->airtableId('requests', $requestId);
        $this->assertEquals([
            'Date' => $day, 'Name' => $this->nameOf($member), 'Reason' => 'Family trip', 'Status' => 'Pending', 'Type' => 'Leave', 'Category' => 'Vacation Leave',
        ], $this->record('Request', $recordId));

        $this->patchJson(self::LEAVE.'/'.$requestId, ['leaveType' => '02 Sick Leave', 'requestDate' => $day, 'reason' => 'Down with the flu'], $headers)->assertOk();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame([1, 1], [$this->writes('POST'), $this->writes('PATCH')]);
        $this->assertSame($recordId, $this->airtableId('requests', $requestId));
        $this->assertSame('Sick Leave', $this->record('Request', $recordId)['Category']);
        $this->assertSame('Down with the flu', $this->record('Request', $recordId)['Reason']);

        $this->postJson(self::LEAVE.'/'.$requestId.'/cancel', [], $headers)->assertOk();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame([['Request', $recordId, []]], $this->sent('DELETE'));
        $this->assertArrayNotHasKey((string) $recordId, $this->base['Request']);
        $this->assertNull($this->airtableId('requests', $requestId));

        // The PROD base offers no Emergency Leave: it lands without a Category rather than waiting forever.
        $emergency = $this->fileLeave($headers, $this->freeRequestDay($member), '03 Emergency Leave');
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $landed = $this->record('Request', $this->airtableId('requests', $emergency));
        $this->assertArrayNotHasKey('Category', $landed);
        $this->assertSame('Pending', $landed['Status']);
    }

    // A claim lands with its receipt, its approval reads Completed in this base, and the payout receipt follows.
    public function test_a_reimbursement_lands_with_its_receipt_then_its_approval_and_payout(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $headers = $this->signIn($member);
        $receipt = $this->post(self::REIMBURSEMENT.'/receipts', ['file' => UploadedFile::fake()->create('grab.jpg', 12, 'image/jpeg')], $headers)
            ->assertCreated()->json('data.receiptId');
        $itemId = (int) $this->postJson(self::REIMBURSEMENT, ['requestDate' => now()->toDateString(), 'items' => [[
            'label' => 'Site visit transport', 'cost' => 850, 'quantity' => 2, 'teamLabel' => '02 Cebu Office',
            'purpose' => 'Client walk-through', 'receiptId' => $receipt,
        ]]], $headers)->assertCreated()->json('data.id');
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $recordId = $this->airtableId('reimbursements', $itemId);
        $filed = $this->record('Reimbursements', $recordId);
        $this->assertSame([['url' => $this->fileUrl('reimbursements', $itemId, 'receipts'), 'filename' => 'grab.jpg']], $filed['Receipts (If Applicable)']);
        $this->assertSame([$member->airtable_record_id], $filed['Reimburse To']);
        $this->assertSame(['Pending', 'Cebu Office', 'Site visit transport'], [$filed['Status'], $filed['Team'], $filed['Item']]);
        $this->assertEquals([850, 2], [$filed['Cost'], $filed['QTY']]);

        $admin = $this->developerAdmin();
        $this->patchJson('/api/development/portal/manage/requests', ['changes' => [[
            'id' => (string) $itemId, 'kind' => 'reimbursement', 'status' => 'approved', 'approverRemarks' => 'Paid with the October batch.',
        ]]], $admin)->assertOk();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame('Completed', $this->record('Reimbursements', $recordId)['Status']);
        $this->assertSame('Paid with the October batch.', $this->record('Reimbursements', $recordId)['Approver Remarks']);
        // A decision re-sends no file: Airtable would fetch it again for nothing.
        $decision = $this->sent('PATCH')[0][2];
        $this->assertArrayNotHasKey('Receipts (If Applicable)', $decision);
        $this->assertArrayNotHasKey('Reimbursement Receipt', $decision);

        $this->post('/api/development/portal/manage/requests/reimbursement/'.$itemId.'/payout-receipt', ['file' => UploadedFile::fake()->create('payout.jpg', 12, 'image/jpeg')], $admin)
            ->assertCreated();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $payout = $this->sent('PATCH')[1][2];
        $this->assertSame([['url' => $this->fileUrl('reimbursements', $itemId, 'payout_receipt'), 'filename' => 'payout.jpg']], $payout['Reimbursement Receipt']);
        $this->assertArrayNotHasKey('Receipts (If Applicable)', $payout);
        $this->assertSame($payout['Reimbursement Receipt'], $this->record('Reimbursements', $recordId)['Reimbursement Receipt']);
        $this->assertSame($filed['Receipts (If Applicable)'], $this->record('Reimbursements', $recordId)['Receipts (If Applicable)']);
    }

    // A person added in Manage / Users and their first report wait in one batch: the person is created
    // first, so the report's Employee No. link resolves in the same run instead of blocking it.
    public function test_a_new_person_and_their_first_report_land_in_one_run(): void
    {
        $this->fakeAirtable();
        $admin = $this->person("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $adminHeaders = $this->signIn($admin);
        $idNo = (string) $this->getJson('/api/development/portal/manage/users/new', $adminHeaders)->assertOk()->json('data.nextIdNo');
        $this->postJson('/api/development/portal/manage/users', [
            'firstName' => 'Lani', 'lastName' => 'Kealoha', 'idNo' => $idNo, 'email' => 'lani.'.Str::lower(Str::random(6)).'@ikaika.test',
            'jobTitle' => 'BIM Modeler', 'department' => 'mis',
        ], $adminHeaders)->assertCreated();
        $person = Employee::query()->where('id_no', $idNo)->firstOrFail();
        // Assigned on the junction directly, as the editor tests do, so they have a project to report on.
        $project = DB::connection('portal')->table('projects')->whereNull('deleted_at')->whereNotNull('project_name')
            ->tap(PortalProjectApproval::openForWork(...))->orderBy('id')->value('id');
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $person->getKey(), 'project_id' => $project, 'role_on_project' => 'member']);
        $date = $this->freeReportDate($person);
        $this->postJson(self::REPORTS, $this->dailyReportBody($person, $date), $this->signIn($person))->assertCreated();
        $line = (int) $this->lastAction('reports.submitted', $date.'-daily')->parameters['parameters']['lineIds'][0];

        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame(['Employees', 'User Reports'], array_column($this->sent('POST'), 0));
        $personRecord = $this->airtableId('employees', (int) $person->getKey());
        $created = $this->record('Employees', $personRecord);
        $this->assertSame([$idNo, 'Lani', 'Kealoha', 'Active', 'User', 'MIS'], [$created['ID No.'], $created['First Name'], $created['Last Name'], $created['Status'], $created['Role'], $created['Department']]);
        // "BIM Modeler" is no option of the base's Job Title, so it is left out rather than holding them back.
        $this->assertArrayNotHasKey('Job Title', $created);
        $this->assertSame([$personRecord], $this->record('User Reports', $this->airtableId('user_reports', $line))['Employee No.']);
    }

    // Added with its people and client, the project is created with every link; a rename sends only its
    // name, and the approval only the approval columns.
    public function test_a_project_is_created_with_its_links_then_renamed_and_approved_column_by_column(): void
    {
        $this->fakeAirtable();
        DB::connection('portal')->table('access_toggles')->where('permission_key', 'like', 'projects%')->delete();
        $admin = $this->person("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive' AND email IS NOT NULL AND email != ''");
        $admin->forceFill(['is_developer' => false, 'department' => 'Engineering'])->save();
        $headers = $this->signIn($admin);
        [$member, $support] = Employee::query()->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->orderBy('id')->limit(2)->get()->all();
        $client = DB::connection('portal')->table('clients')->where('client_id', 'regexp', '^[0-9]{2}$')->orderBy('id')->first();
        $body = [
            'name' => 'Sync Scenario '.Str::random(8), 'leadId' => $admin->getKey(), 'clientId' => (int) $client->id,
            'department' => 'Engineering', 'type' => 'Scan-to-BIM', 'area' => 1250, 'status' => 'ongoing',
            'memberIds' => [$member->getKey()], 'supportMemberIds' => [$support->getKey()],
            'levels' => ['structural' => '300', 'architectural' => 'N/A', 'electrical' => '200', 'plumbing' => '200', 'mechanical-duct' => '250', 'fire-protection' => '100'],
            'links' => [
                'forma' => 'https://autodeskforma.com/project', 'ms-teams' => 'https://teams.microsoft.com/l/channel/sync',
                'ms-planner' => 'https://tasks.office.com/plan/sync', 'ms-loop' => 'https://loop.microsoft.com/p/sync',
                'panoramic' => 'https://panoramic.example.com/tour/sync', 'google-drive' => '/Shared drives/Projects/Sync',
            ],
            'scopeCodes' => [],
        ];
        $projectId = (int) $this->postJson('/api/development/portal/projects', $body, $headers)->assertCreated()->json('data.id');
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $recordId = $this->airtableId('projects', $projectId);
        $created = $this->record('Project/s', $recordId);
        $this->assertSame(DB::connection('portal')->table('projects')->where('id', $projectId)->value('project_name'), $created['Project Name']);
        $this->assertSame([$admin->airtable_record_id], $created['PM ID']);
        $this->assertSame([$member->airtable_record_id], $created['Project Members']);
        $this->assertSame([$support->airtable_record_id], $created['Support Members']);
        $this->assertSame([$client->airtable_record_id], $created['Client']);
        $this->assertSame(['Started', 'Pending', 'Engineering', 'Scan-to-BIM'], [$created['Status'], $created['ProjectCreationStatus'], $created['Department'], $created['Type of Job']]);
        $this->assertEquals(1250, $created['Area (in S.F.)']);

        unset($body['scopeCodes']);
        $this->patchJson('/api/development/portal/projects/'.$projectId, [...$body, 'name' => 'Sync Renamed '.Str::random(6)], $headers)->assertOk();
        $renamed = DB::connection('portal')->table('projects')->where('id', $projectId)->value('project_name');
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame([['Project/s', $recordId, ['Project Name' => $renamed]]], $this->sent('PATCH'));
        $this->assertSame($renamed, $this->record('Project/s', $recordId)['Project Name']);

        $this->patchJson('/api/development/portal/manage/projects/'.$projectId, ['decision' => 'approve'], $headers)->assertOk();
        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $approval = $this->sent('PATCH')[1];
        $this->assertSame(['Project/s', $recordId], [$approval[0], $approval[1]]);
        $this->assertSame(['ProjectCreationStatus', 'ProjectCreationApprover', 'Project Approver Email', 'ProjectCreationApproverRemarks'], array_keys($approval[2]));
        $this->assertCount(2, $this->sent('PATCH'));
        $this->assertSame('Approved', $this->record('Project/s', $recordId)['ProjectCreationStatus']);
        $this->assertSame($admin->email, $this->record('Project/s', $recordId)['Project Approver Email']);
    }

    // A 503 fails the action once and the next run lands it; a 429 waits out Airtable's 30 s lockout and retries.
    public function test_a_transient_failure_is_retried_and_a_rate_limit_waits_out_its_lockout(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $headers = $this->signIn($member);
        $day = $this->freeRequestDay($member);
        $requestId = $this->fileLeave($headers, $day, '01 Vacation Leave');
        app(PortalAirtableSync::class)->run(true);
        $recordId = $this->airtableId('requests', $requestId);

        $this->patchJson(self::LEAVE.'/'.$requestId, ['leaveType' => '01 Vacation Leave', 'requestDate' => $day, 'reason' => 'Moved the trip'], $headers)->assertOk();
        $edit = $this->lastAction('requests.leave', (string) $requestId);
        $this->faults = [['PATCH', 503]];
        app(PortalAirtableSync::class)->run(true);

        $failed = Action::query()->findOrFail($edit->id);
        $this->assertNull($failed->synced_at);
        $this->assertSame(1, (int) $failed->attempts);
        $this->assertStringContainsString('HTTP_503', (string) $failed->last_error);
        $this->assertSame('Family trip', $this->record('Request', $recordId)['Reason']);

        app(PortalAirtableSync::class)->run(true);

        $this->assertTrue($this->synced($edit->id));
        $this->assertSame('Moved the trip', $this->record('Request', $recordId)['Reason']);

        $this->patchJson(self::LEAVE.'/'.$requestId, ['leaveType' => '01 Vacation Leave', 'requestDate' => $day, 'reason' => 'Moved it again'], $headers)->assertOk();
        $again = $this->lastAction('requests.leave', (string) $requestId);
        $this->faults = [['PATCH', 429]];
        $patches = $this->writes('PATCH');
        app(PortalAirtableSync::class)->run(true);

        $this->assertTrue($this->synced($again->id));
        $this->assertSame(0, (int) Action::query()->findOrFail($again->id)->attempts);
        $this->assertSame($patches + 2, $this->writes('PATCH'));
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 30);
        $this->assertSame('Moved it again', $this->record('Request', $recordId)['Reason']);
        $this->assertSame($recordId, $this->airtableId('requests', $requestId));
    }

    // A create whose answer was lost may be in Airtable already, so a plain run holds it; --retry-creates sends it.
    public function test_a_lost_create_is_held_until_retry_creates(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $requestId = $this->fileLeave($this->signIn($member), $this->freeRequestDay($member), '01 Vacation Leave');
        $add = $this->lastAction('requests.leave', (string) $requestId);
        $held = count($this->base['Request']);
        $this->faults = [['POST', 'lost']];

        app(PortalAirtableSync::class)->run(true);
        app(PortalAirtableSync::class)->run(true);

        // One try, never repeated blind.
        $this->assertSame(1, $this->writes('POST'));
        $this->assertNull($this->airtableId('requests', $requestId));
        $this->assertCount($held, $this->base['Request']);
        $this->assertStringContainsString(PortalAirtableSync::UNCERTAIN_CREATE, (string) Action::query()->findOrFail($add->id)->last_error);
        $this->assertFalse($this->synced($add->id));

        $this->travel(15)->seconds();
        $this->artisan('portal:airtable-sync', ['--send' => true, '--retry-creates' => true])->assertExitCode(0);

        $this->assertSame(2, $this->writes('POST'));
        $this->assertTrue($this->synced($add->id));
        $this->assertCount($held + 1, $this->base['Request']);
        $this->assertSame('Vacation Leave', $this->record('Request', $this->airtableId('requests', $requestId))['Category']);
    }

    // A create that lands while another writer gives the row its own record is deleted again, so the
    // base keeps no duplicate, and the next run patches the record the row holds.
    public function test_a_create_whose_row_was_claimed_meanwhile_is_deleted_again(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $requestId = $this->fileLeave($this->signIn($member), $this->freeRequestDay($member), '01 Vacation Leave');
        $add = $this->lastAction('requests.leave', (string) $requestId);
        $rival = 'recRIVAL000000001';
        $this->afterCreate = function (string $table, string $created) use ($requestId, $rival): void {
            $this->afterCreate = null;
            $this->base[$table][$rival] = $this->base[$table][$created];
            DB::connection('portal')->table('requests')->where('id', $requestId)->update(['airtable_record_id' => $rival]);
        };

        app(PortalAirtableSync::class)->run(true);

        $created = sprintf('recFAKE%010d', $this->minted);
        $this->assertSame([['Request', $created, []]], $this->sent('DELETE'));
        $this->assertArrayNotHasKey($created, $this->base['Request']);
        $this->assertSame($rival, $this->airtableId('requests', $requestId));
        $failed = Action::query()->findOrFail($add->id);
        $this->assertNull($failed->synced_at);
        $this->assertSame(1, (int) $failed->attempts);
        $this->assertStringContainsString('The row changed while it was being sent', (string) $failed->last_error);

        app(PortalAirtableSync::class)->run(true);

        $this->assertTrue($this->synced($add->id));
        $this->assertSame(['Request', $rival], array_slice($this->sent('PATCH')[0], 0, 2));
        $this->assertSame(1, $this->writes('POST'));
        $this->assertSame('Vacation Leave', $this->record('Request', $rival)['Category']);
    }

    // Pointed at another base -- one holding none of the records SQL names -- a send refuses before any write.
    public function test_a_send_to_the_wrong_base_writes_nothing_anywhere(): void
    {
        $this->fakeAirtable(holdsTheDatabase: false);
        $member = $this->member();
        $headers = $this->signIn($member);
        $day = $this->freeRequestDay($member);
        $requestId = $this->fileLeave($headers, $day, '01 Vacation Leave');
        // Synced into the right base earlier, then edited: the wrong base would read it as deleted and copy it.
        DB::connection('portal')->table('requests')->where('id', $requestId)->update(['airtable_record_id' => 'recRIGHTBASE00001']);
        $this->patchJson(self::LEAVE.'/'.$requestId, ['leaveType' => '02 Sick Leave', 'requestDate' => $day, 'reason' => 'Edited'], $headers)->assertOk();
        $this->travel(15)->seconds();

        $this->artisan('portal:airtable-sync', ['--send' => true])
            ->expectsOutputToContain('AIRTABLE_BASE_ID names another base')
            ->assertExitCode(1);

        $this->assertSame(0, $this->writes());
        $this->assertSame([], $this->base['Request']);
        $this->assertSame('recRIGHTBASE00001', $this->airtableId('requests', $requestId));
        $actions = Action::query()->where('resource', 'requests.leave')->where('record_id', (string) $requestId)->get();
        $this->assertCount(2, $actions);
        foreach ($actions as $action) {
            $this->assertSame([null, 0, null], [$action->synced_at, (int) $action->attempts, $action->last_error]);
        }
    }

    // Under --backoff a failing action is tried once per window (1, 3, 7 ... minutes after it was written);
    // a plain --send tries it every time.
    public function test_backoff_tries_a_failing_action_at_most_once_per_window(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $requestId = $this->fileLeave($this->signIn($member), $this->freeRequestDay($member), '01 Vacation Leave');
        $add = $this->lastAction('requests.leave', (string) $requestId);
        $written = Carbon::parse($add->created_at);
        $this->faults = array_fill(0, 10, ['POST', 503]);
        $send = function (int $seconds, bool $backoff, int $exit) use ($written): int {
            $this->travelTo($written->copy()->addSeconds($seconds));
            $this->artisan('portal:airtable-sync', ['--send' => true, '--backoff' => $backoff])->assertExitCode($exit);

            return $this->writes('POST');
        };

        $this->assertSame(
            [1, 1, 2, 2, 3, 3, 4],
            // Each wait counts from the last try (2026-10-20): 1 minute, then 2, then 4; an idle minute makes no call.
            [$send(15, true, 1), $send(50, true, 0), $send(80, true, 1), $send(180, true, 0), $send(205, true, 1), $send(400, true, 0), $send(401, false, 1)],
        );
        $this->assertSame(4, (int) Action::query()->findOrFail($add->id)->attempts);
        $this->assertNull($this->airtableId('requests', $requestId));
    }

    // An approved profile change sends the employee only the columns it changed and the document slot it filled.
    public function test_an_approved_profile_change_patches_only_what_it_changed(): void
    {
        $this->fakeAirtable();
        $member = $this->member();
        $bloodType = $member->blood_type === 'AB+' ? 'O-' : 'AB+';
        $phone = '0917'.random_int(1000000, 9999999);
        $asked = $this->post('/api/development/portal/user/information/changes', [
            'bloodType' => $bloodType,
            'phoneNumber' => $phone,
            'files' => ['ss_id' => UploadedFile::fake()->createWithContent('sss.pdf', self::PDF)],
        ], $this->signIn($member))->assertCreated()->json('data.pending.id');
        $head = $this->person("id_no = '".self::HEAD_ADMIN."'");
        $head->forceFill(['is_developer' => false])->save();
        $this->patchJson('/api/development/portal/manage/users/changes/'.$asked, ['decision' => 'approve'], $this->signIn($head))->assertOk();

        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $recordId = (string) $member->airtable_record_id;
        $sent = array_values(array_filter($this->sent('PATCH'), static fn (array $patch): bool => $patch[0] === 'Employees' && $patch[1] === $recordId));
        $this->assertCount(count($this->sent('PATCH')), $sent);
        $keys = array_merge(...array_map(static fn (array $patch): array => array_keys($patch[2]), $sent));
        sort($keys);
        $this->assertSame(['Blood Type', 'Phone Number', 'SS ID'], $keys);
        $record = $this->record('Employees', $recordId);
        $this->assertSame([$bloodType, $phone], [$record['Blood Type'], $record['Phone Number']]);
        $this->assertSame([['url' => $this->fileUrl('employees', (int) $member->getKey(), 'ss_id'), 'filename' => 'sss.pdf']], $record['SS ID']);
    }

    // An admin's grid edit of a private column reaches Airtable by name alone; its value is read from SQL.
    public function test_a_grid_edit_of_a_bank_account_number_is_patched(): void
    {
        $this->fakeAirtable();
        $row = DB::connection('portal')->table('new_employee_data')->whereNotNull('airtable_record_id')->orderBy('id')->first();
        $this->assertNotNull($row);
        $number = '00'.random_int(10000000, 99999999);
        $this->patchJson('/api/development/portal/_grid/new_employee_data/'.$row->id, ['changes' => ['bank_account_number' => $number]], $this->signIn($this->rosterExecutive()))
            ->assertOk();

        app(PortalAirtableSync::class)->run(true);

        $this->assertSame([], $this->unsent());
        $this->assertSame([['New Employee Data', $row->airtable_record_id, ['Bank Account Number' => $number]]], $this->sent('PATCH'));
        $this->assertSame($number, $this->record('New Employee Data', $row->airtable_record_id)['Bank Account Number']);
    }

    /**
     * A base holding every record SQL points at, as the right base does; an empty one is another base.
     */
    private function fakeAirtable(bool $holdsTheDatabase = true): void
    {
        $this->base = array_fill_keys(array_keys(self::BASE), []);
        if ($holdsTheDatabase) {
            $tables = [];
            foreach (AirtableSyncMap::resources() as $resource) {
                $map = AirtableSyncMap::for($resource);
                if ($map !== null && isset(self::BASE[$map['airtable_table']])) {
                    $tables[$map['sql_table']] = $map['airtable_table'];
                }
            }
            foreach ($tables as $sqlTable => $airtableTable) {
                foreach (DB::connection('portal')->table($sqlTable)->whereNotNull('airtable_record_id')->pluck('airtable_record_id') as $id) {
                    $this->base[$airtableTable][(string) $id] = [];
                }
            }
        }
        Http::fake(fn (Request $request): PromiseInterface => $this->answer($request));
    }

    private function answer(Request $request): PromiseInterface
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        if ($host !== 'api.airtable.com') {
            // Cloudinary keeps an upload at its own delivery link; nothing else may leave the test.
            return $host === 'api.cloudinary.com'
                ? Http::response(['secure_url' => 'https://res.cloudinary.com/'.config('services.cloudinary.cloud_name').'/image/upload/v1/ikaika-portal/upload'.++$this->uploads, 'bytes' => 1200])
                : Http::response([], 200);
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (str_starts_with($path, '/v0/meta/')) {
            return Http::response($this->meta());
        }

        $method = $request->method();
        $segments = array_map('rawurldecode', explode('/', trim($path, '/')));
        $table = $segments[2] ?? '';
        $id = $segments[3] ?? null;
        $fields = $method !== 'GET' && is_array($request['fields'] ?? null) ? $request['fields'] : [];
        $this->log[] = [$method, $table, $id, $fields];
        foreach ($this->faults as $at => [$on, $fault]) {
            if ($on === $method) {
                unset($this->faults[$at]);
                if ($fault === 'lost') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(['error' => ['type' => 'HTTP_'.$fault, 'message' => 'Simulated '.$fault]], (int) $fault);
            }
        }
        if (! isset($this->base[$table])) {
            return Http::response(['error' => ['type' => 'TABLE_NOT_FOUND', 'message' => 'Could not find table '.$table]], 404);
        }
        // Airtable answers a record it does not hold, and one the token may not touch, with the same 403.
        $missing = Http::response(['error' => ['type' => 'INVALID_PERMISSIONS_OR_MODEL_NOT_FOUND', 'message' => 'Invalid permissions, or the requested model was not found.']], 403);

        if ($method === 'GET') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            preg_match("/RECORD_ID\(\)='([^']+)'/", (string) ($query['filterByFormula'] ?? ''), $match);
            $wanted = $match[1] ?? null;

            return Http::response(['records' => $wanted !== null && isset($this->base[$table][$wanted])
                ? [['id' => $wanted, 'fields' => $this->base[$table][$wanted]]]
                : []]);
        }
        if ($method === 'DELETE') {
            if ($id === null || ! isset($this->base[$table][$id])) {
                return $missing;
            }
            unset($this->base[$table][$id]);

            return Http::response(['id' => $id, 'deleted' => true]);
        }
        if ($method === 'PATCH' && ($id === null || ! isset($this->base[$table][$id]))) {
            return $missing;
        }

        $refusal = $this->refusal($table, $fields);
        if ($refusal !== null) {
            return Http::response(['error' => ['type' => $refusal[0], 'message' => $refusal[1]]], 422);
        }
        if ($method === 'POST') {
            $id = sprintf('recFAKE%010d', ++$this->minted);
            $this->base[$table][$id] = [];
        }
        foreach ($fields as $name => $value) {
            if ($value === null) {
                unset($this->base[$table][$id][$name]);
            } else {
                $this->base[$table][$id][$name] = $value;
            }
        }
        if ($method === 'POST' && $this->afterCreate !== null) {
            ($this->afterCreate)($table, $id);
        }

        return Http::response(['id' => $id, 'createdTime' => now()->toIso8601String(), 'fields' => $this->base[$table][$id]]);
    }

    /**
     * What Airtable refuses with typecast off: an unknown field, an option it does not offer, a value
     * of the wrong type, a link to a record the linked table lacks, a file it cannot fetch.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: string, 1: string}|null
     */
    private function refusal(string $table, array $fields): ?array
    {
        foreach ($fields as $name => $value) {
            $type = self::BASE[$table][$name] ?? null;
            if ($type === null) {
                return ['UNKNOWN_FIELD_NAME', 'Unknown field name: "'.$name.'"'];
            }
            if ($value === null) {
                continue;
            }
            $fits = match (true) {
                is_array($type) => is_string($value) && in_array($value, $type, true),
                str_starts_with($type, '@') => is_array($value) && array_is_list($value)
                    && array_filter($value, fn (mixed $link): bool => ! is_string($link) || ! isset($this->base[substr($type, 1)][$link])) === [],
                $type === 'multipleAttachments' => is_array($value) && array_is_list($value)
                    && array_filter($value, static fn (mixed $file): bool => ! is_array($file) || ! is_string($file['url'] ?? null) || ! str_starts_with($file['url'], 'https://')) === [],
                in_array($type, ['number', 'currency'], true) => is_int($value) || is_float($value),
                $type === 'checkbox' => is_bool($value),
                $type === 'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                default => is_string($value),
            };
            if (! $fits) {
                return is_array($type) && is_string($value)
                    ? ['INVALID_MULTIPLE_CHOICE_OPTIONS', 'Insufficient permissions to create new select option ""'.$value.'""']
                    : ['INVALID_VALUE_FOR_COLUMN', 'Field "'.$name.'" cannot accept the provided value'];
            }
        }

        return null;
    }

    /**
     * @return array{tables: list<array{name: string, fields: list<array<string, mixed>>}>}
     */
    private function meta(): array
    {
        $tables = [];
        foreach (self::BASE as $table => $fields) {
            $described = [];
            foreach ($fields as $name => $type) {
                $described[] = match (true) {
                    is_array($type) => ['name' => $name, 'type' => 'singleSelect', 'options' => ['choices' => array_map(static fn (string $choice): array => ['name' => $choice], $type)]],
                    str_starts_with($type, '@') => ['name' => $name, 'type' => 'multipleRecordLinks', 'options' => ['linkedTableId' => substr($type, 1)]],
                    default => ['name' => $name, 'type' => $type],
                };
            }
            $tables[] = ['name' => $table, 'fields' => $described];
        }

        return ['tables' => $tables];
    }

    // The writes the base received, of one method or all: an idempotent run leaves this unchanged.
    private function writes(?string $method = null): int
    {
        return count(array_filter($this->log, static fn (array $entry): bool => $entry[0] !== 'GET' && ($method === null || $entry[0] === $method)));
    }

    /**
     * @return list<array{0: string, 1: ?string, 2: array<string, mixed>}> table, record and fields of each request of one method, in order
     */
    private function sent(string $method): array
    {
        return array_values(array_map(
            static fn (array $entry): array => [$entry[1], $entry[2], $entry[3]],
            array_filter($this->log, static fn (array $entry): bool => $entry[0] === $method),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $table, ?string $id): array
    {
        $this->assertNotNull($id, 'SQL holds no Airtable id for this row.');
        $this->assertArrayHasKey($id, $this->base[$table], $table.' holds no record '.$id);

        return $this->base[$table][$id];
    }

    /**
     * Whatever the scenario's ledger left unsent, with its reason: empty once everything landed.
     *
     * @return list<string>
     */
    private function unsent(): array
    {
        return Action::query()->whereNull('synced_at')->orderBy('id')->get()
            ->map(static fn (Action $action): string => $action->resource.' #'.$action->record_id.': '.$action->last_error)
            ->all();
    }

    private function synced(int $actionId): bool
    {
        return Action::query()->findOrFail($actionId)->synced_at !== null;
    }

    private function airtableId(string $table, int $rowId): ?string
    {
        $id = DB::connection('portal')->table($table)->where('id', $rowId)->value('airtable_record_id');

        return is_string($id) ? $id : null;
    }

    // The Airtable id of the row a report line links through one of its junctions.
    private function linkedRecord(string $junction, string $foreign, string $table, int $lineId): ?string
    {
        return DB::connection('portal')->table($junction)->join($table, $table.'.id', '=', $junction.'.'.$foreign)
            ->where($junction.'.user_report_id', $lineId)->value($table.'.airtable_record_id');
    }

    private function fileUrl(string $table, int $rowId, string $field): ?string
    {
        return DB::connection('portal')->table('attachments')->where('table_name', $table)->where('record_id', $rowId)
            ->where('field_name', $field)->orderByDesc('id')->value('file_url');
    }

    private function lastAction(string $resource, string $recordId): Action
    {
        return Action::query()->where('product', 'portal')->where('resource', $resource)->where('record_id', $recordId)
            ->orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fileLeave(array $headers, string $day, string $type): int
    {
        return (int) $this->postJson(self::LEAVE, ['leaveType' => $type, 'startDate' => $day, 'endDate' => $day, 'reason' => 'Family trip'], $headers)
            ->assertCreated()->json('data.0.id');
    }

    // A plain member with a project open for work, never a head admin: the person most scenarios file as.
    private function member(): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')->whereNotNull('first_name')
            ->where('id_no', '!=', self::HEAD_ADMIN)
            ->whereExists(fn ($query) => $query->from('employees_projects')
                ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
                ->whereColumn('employees_projects.employee_id', 'employees.id')
                ->whereNull('projects.deleted_at')
                ->tap(PortalProjectApproval::openForWork(...)))
            ->orderBy('id')
            ->firstOrFail();
    }

    private function person(string $where): Employee
    {
        return Employee::query()->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')->orderBy('id')->firstOrFail();
    }

    // A developer in Developer mode reaches every department's queue, as PortalManageRequestsTest's admin does.
    /**
     * @return array<string, string>
     */
    private function developerAdmin(): array
    {
        $admin = $this->person("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $admin->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $headers = $this->signIn($admin);
        $this->developerModeOn(substr($headers['Authorization'], 7));

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function signIn(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function nameOf(Employee $employee): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($employee->first_name) ? $employee->first_name : null,
            is_string($employee->last_name) ? $employee->last_name : null,
        );
    }
}
