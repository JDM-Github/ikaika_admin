<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

// Bug reports (2026-10-13, user): anyone reports one anonymously, with screenshots; MIS reads and tracks them.
class PortalBugReportsTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/';

    private const UPLOAD = 'https://api.cloudinary.com/v1_1/test-cloud/auto/upload';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_anyone_reports_a_bug_with_screenshots_and_nothing_names_them(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD => Http::response([
            'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/bug-reports/shot.png',
        ], 200)]);
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $logged = PortalLog::query()->count();

        // No location either: a report is not asked where it was sent from.
        $this->flushHeaders();
        $id = $this->withHeaders($headers)->post(self::BASE.'bug-reports', [
            'title' => '  Save does nothing on Transactions ',
            'description' => 'Pressing Save Changes leaves the form open.',
            'page' => 'finance/transactions',
            'images' => [UploadedFile::fake()->image('jamie-phone.jpg'), UploadedFile::fake()->image('second.png')],
        ])->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame('Save does nothing on Transactions', $row->title);
        $this->assertSame('finance/transactions', $row->page);
        $this->assertSame('new', $row->status);
        $this->assertSame(now()->toDateString(), substr((string) $row->reported_on, 0, 10));
        // Nothing in the table can hold who sent it, and no activity was logged for it.
        $this->assertSame(
            ['id', 'title', 'description', 'page', 'status', 'note', 'assignee_id', 'version_level', 'fixed_in', 'reported_on', 'updated_at'],
            Schema::connection('portal')->getColumnListing('bug_reports'),
        );
        $this->assertSame($logged, PortalLog::query()->count());

        // Each screenshot went up under a neutral name, and is served without its metadata.
        $this->assertSame(
            array_fill(0, 2, 'https://res.cloudinary.com/test-cloud/image/upload/q_auto/v1/ikaika-portal/bug-reports/shot.png'),
            DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $id)->pluck('file_url')->all(),
        );
        $bodies = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]->body());
        $this->assertCount(2, $bodies);
        $this->assertTrue($bodies->every(fn (string $body): bool => ! str_contains($body, 'jamie-phone') && str_contains($body, 'screenshot-')));
    }

    public function test_a_report_needs_a_title_and_a_description_and_three_screenshots_at_most(): void
    {
        Http::fake();
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $body = ['title' => 'Broken', 'description' => 'It broke.'];

        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'title' => ' '])
            ->assertStatus(422)->assertJsonPath('message', 'Give the bug a short title.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'description' => ''])
            ->assertStatus(422)->assertJsonPath('message', 'Say what went wrong.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'images' => array_map(
            fn (int $index): UploadedFile => UploadedFile::fake()->image("shot{$index}.png"),
            [1, 2, 3, 4],
        )])->assertStatus(422)->assertJsonPath('message', 'Attach up to three screenshots.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])
            ->assertStatus(422)->assertJsonPath('message', 'A screenshot has to be a JPEG, PNG, WebP or GIF.');

        Http::assertNothingSent();
        // Signed in still: a report is anonymous to MIS, not open to anyone on the internet.
        $this->flushHeaders();
        $this->postJson(self::BASE.'bug-reports', $body)->assertUnauthorized();
    }

    public function test_mis_reads_and_tracks_the_reports_and_nobody_else_does(): void
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Calendar skips Sunday',
            'description' => 'The month view jumps from Saturday to Monday.',
            'page' => 'calendar',
            'status' => 'new',
            'reported_on' => '2026-10-12',
        ]);
        DB::connection('portal')->table('bug_report_images')->insert([
            'bug_report_id' => $id,
            'file_url' => 'https://res.cloudinary.com/test-cloud/image/upload/q_auto/v1/ikaika-portal/bug-reports/cal.png',
            'mime_type' => 'image/png',
            'size_bytes' => 900,
        ]);
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $engineering = $this->headersFor($this->memberOf('Engineering'));

        $listed = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame(['id', 'title', 'description', 'page', 'status', 'note', 'reportedOn', 'assignee', 'versionLevel', 'fixedIn', 'images'], array_keys($listed));
        $this->assertSame('2026-10-12', $listed['reportedOn']);
        $this->assertStringContainsString('c_fill', (string) $listed['images'][0]['thumbUrl']);
        $this->withHeaders($engineering)->getJson(self::BASE.'bug-reports')->assertForbidden();

        // Nobody handles it yet, so nothing else about it moves; assigning it in the same save is enough.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed', 'note' => 'Fixed in the October build.', 'assigneeId' => (string) $analyst->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'fixed')
            ->assertJsonPath('data.note', 'Fixed in the October build.');
        $this->assertTrue(PortalLog::query()
            ->where('employee_id', $analyst->getKey())
            ->where('resource', 'bug-reports')
            ->where('message', 'like', '%marked the bug report Calendar skips Sunday Fixed')
            ->exists());

        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['note' => ''])->assertOk()->assertJsonPath('data.note', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'closed'])
            ->assertStatus(422)->assertJsonPath('message', "Choose New, In progress, Fixed or Won't fix.");
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, [])
            ->assertStatus(422)->assertJsonPath('message', 'Change the status, the note, who handles it or its version.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/999999999', ['status' => 'fixed'])->assertNotFound();
        $this->withHeaders($engineering)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'new'])->assertForbidden();
    }

    // The reported ask (2026-10-13): who in MIS handles a report, and the semantic version its fix needs.
    public function test_mis_assigns_a_report_or_takes_it_and_records_its_version(): void
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Export drops the last row',
            'description' => 'The spreadsheet ends one row short.',
            'status' => 'new',
            'reported_on' => '2026-10-12',
        ]);
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $engineer = $this->memberOf('Engineering');

        // Only active MIS members are offered, and only they can be given it.
        $assignees = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('assignees'));
        $this->assertTrue($assignees->contains('id', (string) $analyst->getKey()));
        $this->assertFalse($assignees->contains('id', (string) $engineer->getKey()));
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $engineer->getKey()])
            ->assertStatus(422)->assertJsonPath('message', 'Choose someone in MIS to handle it.');

        // Taking it is assigning it to yourself, and the log says so.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $analyst->getKey()])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', (string) $analyst->getKey());
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%took the bug report Export drops the last row')->exists());
        $colleague = $assignees->first(fn (array $person): bool => $person['id'] !== (string) $analyst->getKey());
        $this->assertNotNull($colleague, 'No second MIS member to assign.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => $colleague['id']])
            ->assertOk()
            ->assertJsonPath('data.assignee.name', $colleague['name']);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%assigned the bug report Export drops the last row to '.$colleague['name'])->exists());

        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => 'minor', 'fixedIn' => ' 1.4.2 '])
            ->assertOk()
            ->assertJsonPath('data.versionLevel', 'minor')
            ->assertJsonPath('data.fixedIn', '1.4.2');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => 'huge'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose Major, Minor or Patch.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['fixedIn' => 'v1.4'])
            ->assertStatus(422)->assertJsonPath('message', 'Write the version as X.Y.Z, like 1.4.2.');

        // Cleared like any field, and then let go; once nobody handles it, it changes no further.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => '', 'fixedIn' => ''])
            ->assertOk()
            ->assertJsonPath('data.versionLevel', null)
            ->assertJsonPath('data.fixedIn', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => null])
            ->assertOk()
            ->assertJsonPath('data.assignee', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => null, 'versionLevel' => 'patch'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%unassigned the bug report Export drops the last row')->exists());
    }

    // An active ordinary member of the department.
    private function memberOf(string $department): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw('LOWER(TRIM(department)) = ?', [strtolower($department)])
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee, "No active {$department} member.");

        return $employee;
    }
}
