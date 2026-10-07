<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalRecycleBinTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_lists_recycle_bin_under_portal_sections(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);

        $administration = collect($sections)->firstWhere('name', 'administration');
        $this->assertIsArray($administration);
        $this->assertFalse($administration['requires_admin']);
        $this->assertSame('recycle-bin', $administration['resources'][0]['name'] ?? null);
        $this->assertSame(
            '/api/development/portal/administration/recycle-bin',
            $administration['resources'][0]['url'] ?? null,
        );
        $this->assertFalse($administration['resources'][0]['requires_admin'] ?? true);
    }

    public function test_a_member_reads_only_their_own_skinny_bin(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $this->clearBin($actor, $other);

        $this->putInBin($actor, [
            'submittedOn' => '2026-08-20',
            'kind' => 'daily',
            'secret' => 'ACTOR-REMARK',
        ]);
        $this->putInBin($other, [
            'submittedOn' => '2026-08-21',
            'kind' => 'late',
            'secret' => 'OTHER-EMPLOYEE-SECRET',
        ]);
        $this->putInBin($other, [
            'product' => CoreLedger::PRODUCT_ESTIMATOR,
            'submittedOn' => '2026-08-22',
            'kind' => 'daily',
            'secret' => 'ESTIMATOR-SECRET',
        ]);

        $token = $this->loginToken($actor);
        $headers = ['Authorization' => "Bearer {$token}"];

        $response = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&employee_id='.$other->id,
            $headers,
        );

        $response->assertOk()
            ->assertJsonPath('section', 'administration')
            ->assertJsonPath('resource', 'recycle-bin')
            ->assertJsonPath('scope', 'own')
            ->assertJsonPath('canViewAll', false)
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('meta.total', 1);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertIsArray($row);
        $this->assertSame(
            ['id', 'recordId', 'kind', 'submittedOn', 'title', 'resource', 'type', 'deletedAt', 'purgesAt', 'employeeId', 'employeeIdNo', 'employeeName'],
            array_keys($row),
        );
        $this->assertSame('2026-08-20-daily', $row['recordId']);
        $this->assertSame('daily', $row['kind']);
        $this->assertSame('Daily Report', $row['title']);
        $this->assertSame('report', $row['type']);
        $this->assertSame((int) $actor->id, $row['employeeId']);
        $this->assertSame([], $response->json('employees'));
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('lines', $row);
        $this->assertArrayNotHasKey('bank_account_number', $row);

        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $encoded);
        $this->assertStringNotContainsString('ESTIMATOR-SECRET', $encoded);
        $this->assertStringNotContainsString('ACTOR-REMARK', $encoded);
    }

    public function test_an_admin_reads_all_recycled_and_can_filter_by_employee(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $other = $this->otherActiveMember($member);
        $this->emptyPortalBin();

        $this->putInBin($member, ['submittedOn' => '2026-08-10', 'kind' => 'daily']);
        $this->putInBin($other, ['submittedOn' => '2026-08-11', 'kind' => 'late']);
        $this->putInBin($admin, ['submittedOn' => '2026-08-12', 'kind' => 'daily']);

        $token = $this->loginToken($admin);
        $headers = ['Authorization' => "Bearer {$token}"];

        $own = $this->getJson(
            '/api/development/portal/administration/recycle-bin',
            $headers,
        )->assertOk();

        $this->assertTrue($own->json('canViewAll'));
        $this->assertSame('own', $own->json('scope'));
        $this->assertSame(1, $own->json('counts.total'));
        $this->assertSame(['2026-08-12-daily'], collect($own->json('data'))->pluck('recordId')->all());

        $all = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all',
            $headers,
        )->assertOk();

        $this->assertTrue($all->json('canViewAll'));
        $this->assertSame('all', $all->json('scope'));
        $this->assertSame(3, $all->json('counts.total'));
        $ids = collect($all->json('data'))->pluck('recordId')->all();
        $this->assertContains('2026-08-10-daily', $ids);
        $this->assertContains('2026-08-11-late', $ids);
        $this->assertContains('2026-08-12-daily', $ids);
        $employeeIds = collect($all->json('employees'))->pluck('id')->all();
        $this->assertContains((string) $member->id, $employeeIds);
        $this->assertContains((string) $other->id, $employeeIds);

        $filtered = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&employee_id='.$member->id,
            $headers,
        )->assertOk();

        $filteredIds = collect($filtered->json('data'))->pluck('recordId')->all();
        $this->assertContains('2026-08-10-daily', $filteredIds);
        $this->assertNotContains('2026-08-11-late', $filteredIds);
        $this->assertNotContains('2026-08-12-daily', $filteredIds);
        $this->assertSame(1, $filtered->json('counts.total'));
        $this->assertContains((string) $other->id, collect($filtered->json('employees'))->pluck('id')->all());
    }

    public function test_the_bin_filters_by_type_and_still_hides_other_members_from_a_member_token(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $this->emptyPortalBin();

        $this->putInBin($admin, ['submittedOn' => '2026-08-20', 'kind' => 'daily']);
        $this->putInBin($admin, [
            'submittedOn' => '2026-08-21',
            'kind' => 'daily',
            'resource' => 'requests.leave',
        ]);
        $this->putInBin($admin, [
            'submittedOn' => '2026-08-22',
            'kind' => 'daily',
            'resource' => 'projects.track',
        ]);
        $this->putInBin($member, [
            'submittedOn' => '2026-08-23',
            'kind' => 'daily',
            'resource' => 'requests.leave',
        ]);

        $adminHeaders = ['Authorization' => 'Bearer '.$this->loginToken($admin)];

        $requests = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&type=request',
            $adminHeaders,
        )->assertOk();

        $this->assertSame(2, $requests->json('counts.total'));
        // The type tabs' counts ignore the open tab, and All is the three together.
        $types = $requests->json('counts.types');
        $this->assertSame(2, $types['request']);
        $this->assertSame($types['report'] + $types['request'] + $types['project'], $types['all']);
        $this->assertSame(['request'], collect($requests->json('data'))->pluck('type')->unique()->values()->all());
        $this->assertNotContains('2026-08-20-daily', collect($requests->json('data'))->pluck('recordId')->all());

        $projects = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&type=project',
            $adminHeaders,
        )->assertOk();

        $this->assertSame(1, $projects->json('counts.total'));
        $this->assertSame('project', $projects->json('data.0.type'));

        $reports = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&type=report',
            $adminHeaders,
        )->assertOk();

        $this->assertSame(1, $reports->json('counts.total'));
        $this->assertSame('report', $reports->json('data.0.type'));

        $memberHeaders = ['Authorization' => 'Bearer '.$this->loginToken($member)];
        $ownRequests = $this->getJson(
            '/api/development/portal/administration/recycle-bin?scope=all&type=request',
            $memberHeaders,
        )->assertOk();

        $this->assertSame('own', $ownRequests->json('scope'));
        $this->assertSame(1, $ownRequests->json('counts.total'));
        $this->assertSame('2026-08-23-daily', $ownRequests->json('data.0.recordId'));
    }

    public function test_members_are_allowed_and_guests_are_not(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);

        $this->getJson('/api/development/portal/administration/recycle-bin')
            ->assertUnauthorized();

        $this->getJson('/api/development/portal/administration/recycle-bin', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();
    }

    public function test_the_bin_uses_allow_listed_page_sizes(): void
    {
        $token = $this->loginToken($this->activeMember());
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/development/portal/administration/recycle-bin?per_page=10', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson('/api/development/portal/administration/recycle-bin?per_page=7', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_deleting_a_report_bumps_the_bin_cache(): void
    {
        $actor = $this->activeMember();
        $this->clearBin($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->putInBin($actor, ['submittedOn' => '2026-07-01', 'kind' => 'daily']);

        $this->getJson('/api/development/portal/administration/recycle-bin', $headers)
            ->assertOk()
            ->assertJsonPath('counts.total', 1);

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);

        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $headers,
        )->assertNoContent();

        $listed = $this->getJson('/api/development/portal/administration/recycle-bin', $headers)
            ->assertOk();
        $this->assertSame(2, $listed->json('counts.total'));
        $ids = collect($listed->json('data'))->pluck('recordId')->all();
        $this->assertContains($today.'-daily', $ids);
        $this->assertContains('2026-07-01-daily', $ids);
    }

    public function test_a_member_restores_their_own_report_and_keeps_the_delete_action(): void
    {
        $actor = $this->activeMember();
        $this->clearBin($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];
        $recycleKey = CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily');

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);

        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $headers,
        )->assertNoContent();

        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->first();
        $this->assertNotNull($recycled);

        $this->postJson(
            '/api/development/portal/administration/recycle-bin/'.$recycled->id.'/restore',
            [],
            $headers,
        )->assertNoContent();

        $listed = $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $headers,
        )->assertOk();
        $this->assertContains($today.'-daily', collect($listed->json('data'))->pluck('id')->all());

        $this->assertSame(0, Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->count());

        $this->assertNotNull(
            Action::query()
                ->where('product', CoreLedger::PRODUCT_PORTAL)
                ->where('recycle_key', $recycleKey)
                ->where('action_type', CoreActionType::DELETE)
                ->first(),
        );
        $added = Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->where('action_type', CoreActionType::ADD)
            ->first();
        $this->assertNotNull($added);
        $this->assertTrue($added->parameters['parameters']['restored'] ?? false);
        $this->assertNotEmpty($added->parameters['parameters']['lineIds']);
        $this->assertSame((int) $actor->getKey(), $added->actor_id);
    }

    public function test_a_member_cannot_restore_someone_elses_row(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $otherHeaders = ['Authorization' => 'Bearer '.$this->loginToken($other)];
        $actorHeaders = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($other, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);
        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $otherHeaders,
        )->assertNoContent();

        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', CoreRecycleKey::submittedReport((int) $other->getKey(), $today, 'daily'))
            ->first();
        $this->assertNotNull($recycled);

        $this->postJson(
            '/api/development/portal/administration/recycle-bin/'.$recycled->id.'/restore',
            [],
            $actorHeaders,
        )->assertNotFound();

        $this->assertSame(1, Recycle::query()->where('id', $recycled->id)->count());
    }

    // All Recycle only looks: restoring someone else's item could backfire on them, so they restore their own.
    public function test_an_admin_sees_another_members_row_but_cannot_restore_it(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $memberHeaders = ['Authorization' => 'Bearer '.$this->loginToken($member)];
        $adminHeaders = ['Authorization' => 'Bearer '.$this->loginToken($admin)];
        $recycleKey = CoreRecycleKey::submittedReport((int) $member->getKey(), $today, 'daily');

        $this->insertLine($member, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);
        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $memberHeaders,
        )->assertNoContent();

        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->first();
        $this->assertNotNull($recycled);

        $this->getJson('/api/development/portal/administration/recycle-bin/'.$recycled->id, $adminHeaders)->assertOk();
        $this->postJson(
            '/api/development/portal/administration/recycle-bin/'.$recycled->id.'/restore',
            [],
            $adminHeaders,
        )->assertForbidden()->assertJsonPath('message', 'All Recycle is view only: people restore their own items.');

        $this->assertSame(1, Recycle::query()->where('id', $recycled->id)->count());
        $memberList = $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $memberHeaders,
        )->assertOk();
        $this->assertNotContains($today.'-daily', collect($memberList->json('data'))->pluck('id')->all());
        $this->assertFalse(Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->where('action_type', CoreActionType::ADD)
            ->where('actor_id', $admin->getKey())
            ->exists());
    }

    public function test_restore_is_refused_when_a_live_report_already_occupies_the_day(): void
    {
        $actor = $this->activeMember();
        $this->clearBin($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];
        $recycleKey = CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily');

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);
        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $headers,
        )->assertNoContent();

        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->first();
        $this->assertNotNull($recycled);

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 4,
        ], $project, $activity, $earn);

        $this->postJson(
            '/api/development/portal/administration/recycle-bin/'.$recycled->id.'/restore',
            [],
            $headers,
        )->assertStatus(422);

        $this->assertSame(1, Recycle::query()->where('id', $recycled->id)->count());
    }

    public function test_deleting_a_report_stores_the_generated_report_on_the_snapshot(): void
    {
        $actor = $this->activeMember();
        $this->clearBin($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];
        $recycleKey = CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily');

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
            'change_in_elements' => 2,
            'remarks' => 'SITE-VISIT-REMARK',
        ], $project, $activity, $earn);

        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $headers,
        )->assertNoContent();

        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', $recycleKey)
            ->first();
        $this->assertNotNull($recycled);
        $payload = is_array($recycled->payload) ? $recycled->payload : [];
        $this->assertArrayHasKey('report', $payload);
        $this->assertIsArray($payload['report']);
        $this->assertArrayHasKey('entries', $payload['report']);
        $this->assertArrayHasKey('lines', $payload);
        $this->assertSame('SITE-VISIT-REMARK', $payload['report']['reason'] ?? null);

        $shown = $this->getJson(
            '/api/development/portal/administration/recycle-bin/'.$recycled->id,
            $headers,
        )->assertOk();

        $data = $shown->json('data');
        $this->assertIsArray($data);
        $this->assertSame($today.'-daily', $data['recordId']);
        $this->assertSame('daily', $data['kind']);
        $this->assertSame('SITE-VISIT-REMARK', $data['reason']);
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayNotHasKey('payload', $data);
        $this->assertArrayNotHasKey('lines', $data);
        $this->assertArrayNotHasKey('report', $data);
        $this->assertIsArray($data['entries']);
        $this->assertNotEmpty($data['entries']);
        $entry = $data['entries'][0];
        $this->assertIsArray($entry);
        $this->assertSame(
            ['id', 'projectLabel', 'activityLabel', 'earnCodeLabel', 'hoursRendered', 'elementChange'],
            array_keys($entry),
        );
        $this->assertNotSame('Unassigned', $entry['projectLabel']);
        $this->assertEquals(8, $entry['hoursRendered']);
        $this->assertEquals(2, $entry['elementChange']);

        $listed = $this->getJson('/api/development/portal/administration/recycle-bin', $headers)
            ->assertOk();
        $row = collect($listed->json('data'))->firstWhere('recordId', $today.'-daily');
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('entries', $row);
        $this->assertArrayNotHasKey('reason', $row);
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('lines', $row);

        $encoded = $shown->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('"lines"', $encoded);
        $this->assertStringNotContainsString('"payload"', $encoded);
    }

    public function test_show_falls_back_to_hours_when_the_snapshot_has_no_generated_report(): void
    {
        $actor = $this->activeMember();
        $this->clearBin($actor);
        $id = $this->putInBin($actor, [
            'submittedOn' => '2026-08-20',
            'kind' => 'daily',
            'secret' => 'LEGACY-REMARK',
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $shown = $this->getJson(
            '/api/development/portal/administration/recycle-bin/'.$id,
            $headers,
        )->assertOk();

        $data = $shown->json('data');
        $this->assertIsArray($data);
        $this->assertSame('LEGACY-REMARK', $data['reason']);
        $this->assertArrayNotHasKey('lines', $data);
        $this->assertSame('Unassigned', $data['entries'][0]['projectLabel'] ?? null);
        $this->assertEquals(8, $data['entries'][0]['hoursRendered'] ?? null);
    }

    public function test_a_member_cannot_show_someone_elses_row(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $id = $this->putInBin($other, [
            'submittedOn' => '2026-08-21',
            'kind' => 'late',
            'secret' => 'OTHER-SHOW-SECRET',
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $response = $this->getJson(
            '/api/development/portal/administration/recycle-bin/'.$id,
            $headers,
        )->assertNotFound();

        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('OTHER-SHOW-SECRET', $encoded);
    }

    public function test_an_admin_shows_another_members_generated_report(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $member = $this->activeMember();
        $id = $this->putInBin($member, [
            'submittedOn' => '2026-08-10',
            'kind' => 'daily',
            'secret' => 'MEMBER-REMARK',
        ]);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($admin)];

        $shown = $this->getJson(
            '/api/development/portal/administration/recycle-bin/'.$id,
            $headers,
        )->assertOk();

        $this->assertSame('MEMBER-REMARK', $shown->json('data.reason'));
        $this->assertSame((int) $member->id, $shown->json('data.employeeId'));
    }

    public function test_restore_requires_a_session(): void
    {
        $this->postJson('/api/development/portal/administration/recycle-bin/1/restore')
            ->assertUnauthorized();
    }

    public function test_show_requires_a_session(): void
    {
        $this->getJson('/api/development/portal/administration/recycle-bin/1')
            ->assertUnauthorized();
    }

    // Their own bin follows User / Recycle Bin, and restoring their own item follows its Restore rule.
    public function test_the_own_bin_follows_its_rules(): void
    {
        $member = $this->activeMember();
        $member->forceFill(['is_developer' => false])->save();
        DB::connection('portal')->table('access_toggles')->where('employee_id', $member->id)->delete();
        $this->emptyPortalBin();
        $theirs = $this->putInBin($member);
        $token = $this->loginToken($member);
        $switch = fn (string $key) => $this->withToken($token)
            ->patchJson('/api/development/portal/access/switches', ['key' => $key, 'on' => false])->assertOk();

        $switch('user.recycle.restore');
        $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin/'.$theirs)->assertOk();
        $this->withToken($token)->postJson('/api/development/portal/administration/recycle-bin/'.$theirs.'/restore')
            ->assertForbidden()->assertJsonPath('message', 'You do not have access to this page.');

        $switch('user.recycle');
        $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin')->assertForbidden();
        $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin/'.$theirs)->assertForbidden();
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->where('id', '!=', $actor->id)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    public function test_a_department_admin_sees_and_restores_only_their_department_in_the_all_view(): void
    {
        [$inside, $outside] = $this->membersInBothDepartments();
        $admin = $this->adminWhoIsNotExecutive(developer: false);
        $this->emptyPortalBin();
        $this->putInBin($inside, ['submittedOn' => '2026-08-10']);
        $theirs = $this->putInBin($outside, ['submittedOn' => '2026-08-11']);
        $mine = $this->putInBin($admin, ['submittedOn' => '2026-08-12']);
        $token = $this->loginToken($admin);

        $all = $this->withToken($token)
            ->getJson('/api/development/portal/administration/recycle-bin?scope=all')
            ->assertOk();

        $ids = collect($all->json('data'))->pluck('recordId')->all();
        $this->assertContains('2026-08-10-daily', $ids);
        $this->assertContains('2026-08-12-daily', $ids);
        $this->assertNotContains('2026-08-11-daily', $ids);
        $this->assertNotContains((string) $outside->id, collect($all->json('employees'))->pluck('id')->all());

        $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin/'.$theirs)->assertNotFound();
        $this->withToken($token)->postJson('/api/development/portal/administration/recycle-bin/'.$theirs.'/restore')->assertNotFound();
        $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin/'.$mine)->assertOk();
    }

    // Default holds a developer to their department; Developer mode sees everyone.
    public function test_a_developer_sees_every_department_only_in_developer_mode(): void
    {
        [, $outside] = $this->membersInBothDepartments();
        $this->emptyPortalBin();
        $this->putInBin($outside, ['submittedOn' => '2026-08-11']);
        $admin = $this->adminWhoIsNotExecutive();
        $token = $this->loginToken($admin);

        $real = $this->withToken($token)->getJson('/api/development/portal/administration/recycle-bin?scope=all')->assertOk();
        $this->assertContains('2026-08-11-daily', collect($real->json('data'))->pluck('recordId')->all());

        $preview = $this->withToken($this->defaultToken($admin))
            ->getJson('/api/development/portal/administration/recycle-bin?scope=all')->assertOk();
        $this->assertNotContains('2026-08-11-daily', collect($preview->json('data'))->pluck('recordId')->all());
    }

    // Executives are managed from their own portal: only they see their own trash.
    public function test_an_executive_s_rows_are_hidden_from_everyone_but_the_executive(): void
    {
        $executive = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($executive);
        $this->emptyPortalBin();
        $theirs = $this->putInBin($executive, ['submittedOn' => '2026-08-13']);
        $developer = $this->loginToken($this->adminWhoIsNotExecutive());

        $all = $this->withToken($developer)->getJson('/api/development/portal/administration/recycle-bin?scope=all')->assertOk();
        $this->assertSame([], $all->json('data'));
        $this->withToken($developer)->getJson('/api/development/portal/administration/recycle-bin/'.$theirs)->assertNotFound();

        $this->withToken($this->loginToken($executive))
            ->getJson('/api/development/portal/administration/recycle-bin/'.$theirs)
            ->assertOk();
    }

    // A developer sees every department, so tests about the bin itself do not depend on who is where.
    private function adminWhoIsNotExecutive(bool $developer = true): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => $developer, 'department' => 'MIS'])->save();

        return $employee;
    }

    /**
     * Two active members, one in MIS written loosely and one in Engineering.
     *
     * @return array{0: Employee, 1: Employee}
     */
    private function membersInBothDepartments(): array
    {
        $inside = $this->activeMember();
        $outside = $this->otherActiveMember($inside);
        $inside->forceFill(['department' => ' mis ', 'is_developer' => false])->save();
        $outside->forceFill(['department' => 'Engineering', 'is_developer' => false])->save();

        return [$inside, $outside];
    }

    // A developer fixture acts in Developer mode, so the bin itself is tested across departments.
    private function loginToken(Employee $employee): string
    {
        $token = $this->defaultToken($employee);

        return (bool) $employee->is_developer ? $this->developerModeOn($token) : $token;
    }

    // A fresh sign-in, which always starts in Default.
    private function defaultToken(Employee $employee): string
    {
        $this->assertNotEmpty($employee->id_no);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function emptyPortalBin(): void
    {
        Recycle::query()->where('product', CoreLedger::PRODUCT_PORTAL)->delete();
    }

    private function clearBin(Employee ...$employees): void
    {
        $ids = array_map(fn (Employee $employee): int => (int) $employee->getKey(), $employees);
        Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->whereIn('deleted_by', $ids)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function putInBin(Employee $employee, array $options = []): int
    {
        $date = (string) ($options['submittedOn'] ?? '2026-08-20');
        $kind = (string) ($options['kind'] ?? 'daily');
        $product = (string) ($options['product'] ?? CoreLedger::PRODUCT_PORTAL);
        $resource = (string) ($options['resource'] ?? 'reports.submitted');
        $recordId = $date.'-'.$kind;
        $recycleKey = CoreRecycleKey::submittedReport((int) $employee->getKey(), $date, $kind);
        if ($product !== CoreLedger::PRODUCT_PORTAL) {
            $recycleKey = 'estimator-'.$recycleKey;
        }

        Recycle::query()
            ->where('product', $product)
            ->where('recycle_key', $recycleKey)
            ->delete();

        return (int) Recycle::query()->insertGetId([
            'product' => $product,
            'recycle_key' => $recycleKey,
            'database_target' => 'portal.user_reports',
            'resource' => $resource,
            'record_id' => $recordId,
            'payload' => json_encode([
                'id' => $recordId,
                'kind' => $kind,
                'submittedOn' => $date,
                'title' => $kind === PortalSubmittedReportPresenter::KIND_LATE ? 'Late Report' : 'Daily Report',
                'lines' => [
                    [
                        'id' => 1,
                        'hours_rendered' => 8,
                        'remarks' => $options['secret'] ?? null,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'deleted_by' => (int) $employee->getKey(),
            'deleted_by_id_no' => $employee->id_no,
            'purges_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertLine(
        Employee $employee,
        array $attributes,
        object $project,
        object $activity,
        object $earn,
    ): int {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $attributes['report_date'],
            'hours_rendered' => $attributes['hours_rendered'] ?? 8,
            'change_in_elements' => $attributes['change_in_elements'] ?? 0,
            'remarks' => $attributes['remarks'] ?? null,
            'late_submission' => $attributes['late_submission'] ?? null,
            'approval' => 'Approved',
        ]);

        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $id,
        ]);
        DB::connection('portal')->table('projects_user_reports')->insert([
            'project_id' => $project->id,
            'user_report_id' => $id,
        ]);
        DB::connection('portal')->table('user_reports_activity_codes')->insert([
            'user_report_id' => $id,
            'activity_code_id' => $activity->id,
        ]);
        DB::connection('portal')->table('user_reports_earn_codes')->insert([
            'user_report_id' => $id,
            'earn_code_id' => $earn->id,
        ]);

        return $id;
    }

    private function firstProject(): object
    {
        $row = DB::connection('portal')->table('projects')->whereNotNull('project_name')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function firstActivity(): object
    {
        $row = DB::connection('portal')->table('activity_codes')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function firstEarnCode(): object
    {
        $row = DB::connection('portal')->table('earn_codes')->first();
        $this->assertNotNull($row);

        return $row;
    }
}
