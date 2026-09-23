<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PortalInboxTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_lists_user_notifications(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);

        $user = collect($sections)->firstWhere('name', 'user');
        $this->assertIsArray($user);
        $notifications = collect($user['resources'])->firstWhere('name', 'notifications');
        $this->assertIsArray($notifications);
        $this->assertSame(
            '/api/development/portal/user/notifications',
            $notifications['url'] ?? null,
        );
    }

    public function test_a_guest_cannot_read_the_inbox(): void
    {
        $this->getJson('/api/development/portal/user/notifications')
            ->assertUnauthorized();
    }

    public function test_a_member_reads_only_their_own_skinny_inbox(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);
        $ownId = $audit->notify(
            (int) $actor->getKey(),
            'requests.leave.approved',
            'Request approved',
            'Your leave request was approved by Ada.',
            '/requests/user-requests',
            'Open requests',
            ['recordId' => '41'],
            (int) $other->getKey(),
        );
        $audit->notify(
            (int) $other->getKey(),
            'requests.leave.approved',
            'Request approved',
            'Someone else should not see this.',
            '/requests/user-requests',
            'Open requests',
        );

        $response = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/notifications')
            ->assertOk()
            ->assertJsonPath('section', 'user')
            ->assertJsonPath('resource', 'notifications');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains((string) $ownId, $ids);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.unread'));
        $row = collect($response->json('data'))->firstWhere('id', (string) $ownId);
        $this->assertIsArray($row);
        $this->assertSame('Request approved', $row['title']);
        $this->assertSame('/requests/user-requests', $row['linkPath']);
        $this->assertFalse($row['isRead']);
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertArrayNotHasKey('token', $row);
        $this->assertDoesNotMatchRegularExpression('/Someone else should not see this/', json_encode($response->json('data')));
    }

    public function test_the_inbox_returns_five_rows_per_page(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);
        for ($index = 1; $index <= 6; $index++) {
            $audit->notify(
                (int) $actor->getKey(),
                'system',
                'Notice '.$index,
                'Body '.$index.'.',
            );
        }

        $first = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/notifications')
            ->assertOk();
        $this->assertCount(5, $first->json('data'));
        $this->assertSame(5, $first->json('meta.per_page'));
        $this->assertSame(1, $first->json('meta.current_page'));
        $this->assertGreaterThanOrEqual(2, $first->json('meta.last_page'));

        $second = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'UTC'])
            ->getJson('/api/development/portal/user/notifications?page=2')
            ->assertOk();
        $this->assertLessThanOrEqual(5, count($second->json('data')));
        $this->assertSame(2, $second->json('meta.current_page'));
        $this->assertNotSame(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        );
    }

    public function test_a_member_marks_one_notice_read_and_cannot_mark_another_members(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);
        $ownId = $audit->notify(
            (int) $actor->getKey(),
            'system',
            'Welcome',
            'Your portal account is ready.',
        );
        $otherId = $audit->notify(
            (int) $other->getKey(),
            'system',
            'Welcome',
            'Your portal account is ready.',
        );

        $this->withToken($token)
            ->patchJson('/api/development/portal/user/notifications/'.$ownId.'/read')
            ->assertOk()
            ->assertJsonPath('saved', true);
        $this->assertNotNull(PortalNotification::query()->find($ownId)?->read_at);

        $this->withToken($token)
            ->patchJson('/api/development/portal/user/notifications/'.$otherId.'/read')
            ->assertNotFound();
        $this->assertNull(PortalNotification::query()->find($otherId)?->read_at);
    }

    public function test_a_member_marks_the_whole_inbox_read(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $audit = app(PortalAudit::class);
        $audit->notify((int) $actor->getKey(), 'system', 'One', 'First notice.');
        $audit->notify((int) $actor->getKey(), 'system', 'Two', 'Second notice.');

        $this->withToken($token)
            ->postJson('/api/development/portal/user/notifications/read-all')
            ->assertOk();

        $unread = PortalNotification::query()
            ->where('employee_id', $actor->getKey())
            ->whereNull('read_at')
            ->count();
        $this->assertSame(0, $unread);
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
            ->where('id', '!=', $actor->getKey())
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $response = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
