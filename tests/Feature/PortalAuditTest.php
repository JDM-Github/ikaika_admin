<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    // Every username token a message carries must be one the portal swaps; a lowercase "your" once
    // rendered raw on the Logs page (2026-10-14, user).
    public function test_every_username_token_is_one_the_portal_swaps(): void
    {
        $supported = ['{UserName|You}', '{UserName|Your}', "{UserName|You're}"];
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/\{UserName\|[^}]*\}/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $token) {
                $found[$token] = true;
            }
        }
        $unsupported = array_values(array_diff(array_keys($found), $supported));

        $this->assertSame([], $unsupported, 'Tokens the portal cannot swap: '.implode(', ', $unsupported));
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

        $inbox = PortalNotification::query()
            ->where('employee_id', $requester->id)
            ->where('type', 'requests.leave.approved')
            ->where('actor_id', $approver->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($inbox);
        $this->assertSame('Request approved', $inbox->title);
        $this->assertSame('/requests/user-requests', $inbox->link_path);
        $this->assertSame(
            "Your Sick Leave request for Monday, September 7, 2026 has been approved by {$approverName}.",
            $inbox->message,
        );
    }

    // Logs carry the network's place only (2026-10-19, supervisor): a device place and its coordinate are dropped.
    public function test_a_device_place_is_never_stored_only_the_network_guess(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        Cache::forget('portal:location:ip:'.hash('sha256', '203.0.113.9'));
        Http::fake([
            'https://ipwho.is/203.0.113.9' => Http::response([
                'success' => true,
                'city' => 'Calamba City',
                'region' => 'Laguna',
                'country' => 'Philippines',
            ]),
        ]);

        $id = app(PortalAudit::class)->record(
            $employee,
            'PATCH',
            'requests.leave',
            'Updated a leave request',
            'location-device-dropped',
            Request::create(
                '/api/development/portal/requests/leave/41',
                'PATCH',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '203.0.113.9',
                    'HTTP_X_PORTAL_LOCATION' => 'Parian, Calamba City',
                    'HTTP_X_PORTAL_LOCATION_SOURCE' => 'device',
                    'HTTP_X_PORTAL_LOCATION_LAT' => '14.2117000',
                    'HTTP_X_PORTAL_LOCATION_LNG' => '121.1642000',
                ],
            ),
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame('Calamba City, Laguna, Philippines', $row->location_label);
        $this->assertSame('ip', $row->location_source);
        $this->assertNull($row->location_lat);
        $this->assertNull($row->location_lng);
    }

    // The portal's own IP lookup is the network's place, so it is kept as sent -- still without a coordinate.
    public function test_the_portal_network_place_is_kept_without_a_coordinate(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        Http::fake();

        $id = app(PortalAudit::class)->record(
            $employee,
            'PATCH',
            'requests.leave',
            'Updated a leave request',
            'location-network',
            Request::create(
                '/api/development/portal/requests/leave/41',
                'PATCH',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '8.8.8.8',
                    'HTTP_X_PORTAL_LOCATION' => 'Calamba, Laguna, Philippines',
                    'HTTP_X_PORTAL_LOCATION_SOURCE' => 'ip',
                    'HTTP_X_PORTAL_LOCATION_LAT' => '14.2117000',
                ],
            ),
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame('Calamba, Laguna, Philippines', $row->location_label);
        $this->assertSame('ip', $row->location_source);
        $this->assertNull($row->location_lat);
        Http::assertNothingSent();
    }

    public function test_never_attaches_a_coordinate_to_the_server_side_ip_fallback(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        $cacheKey = 'portal:location:ip:'.hash('sha256', '203.0.113.9');
        Cache::forget($cacheKey);
        Http::fake([
            'https://ipwho.is/203.0.113.9' => Http::response([
                'success' => true,
                'city' => 'Calamba City',
                'region' => 'Laguna',
                'country' => 'Philippines',
            ]),
        ]);

        // No X-Portal-Location header at all -- this is the pure server-side IP guess, which
        // never carries a device-verified coordinate no matter what the lookup API returns.
        $id = app(PortalAudit::class)->record(
            $employee,
            'PATCH',
            'requests.leave',
            'Updated a leave request',
            'location-ip-no-coordinate',
            Request::create(
                '/api/development/portal/requests/leave/41',
                'PATCH',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '203.0.113.9'],
            ),
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame('ip', $row->location_source);
        $this->assertNull($row->location_lat);
        $this->assertNull($row->location_lng);
    }

    public function test_leaves_loopback_location_unavailable_without_an_ip_lookup(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        config(['services.ipwhois.lookup_private' => false]);
        Http::fake();

        $id = app(PortalAudit::class)->record(
            $employee,
            'POST',
            'auth.login',
            'Signed in to the portal',
            'location-loopback',
            Request::create(
                '/api/development/portal/auth/login',
                'POST',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertNull($row->location_label);
        $this->assertNull($row->location_source);
        Http::assertNothingSent();
    }

    public function test_resolves_loopback_through_the_server_egress_when_enabled(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        Cache::forget('portal:location:ip:'.hash('sha256', 'egress'));
        config(['services.ipwhois.lookup_private' => true]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ipwho.is')) {
                return Http::response([
                    'success' => true,
                    'city' => 'Calamba',
                    'region' => 'Calabarzon (Region IV-A)',
                    'country' => 'Philippines',
                ]);
            }

            return Http::response([], 404);
        });

        $id = app(PortalAudit::class)->record(
            $employee,
            'POST',
            'auth.login',
            'Signed in to the portal',
            'location-egress',
            Request::create(
                '/api/development/portal/auth/login',
                'POST',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        $row = PortalLog::query()->find($id);
        $this->assertNotNull($row);
        $this->assertSame('Calamba, Calabarzon (Region IV-A), Philippines', $row->location_label);
        $this->assertSame('ip', $row->location_source);
    }

    public function test_caches_a_public_ip_location_and_fails_open(): void
    {
        $employee = Employee::query()->orderBy('id')->first();
        $this->assertNotNull($employee);
        $cacheKey = 'portal:location:ip:'.hash('sha256', '8.8.8.8');
        Cache::forget($cacheKey);
        Http::fake([
            'https://ipwho.is/8.8.8.8' => Http::response([
                'success' => true,
                'city' => 'Calamba City',
                'region' => 'Laguna',
                'country' => 'Philippines',
            ]),
        ]);

        $request = Request::create(
            '/api/development/portal/requests/leave/41',
            'PATCH',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '8.8.8.8'],
        );
        $audit = app(PortalAudit::class);
        $firstId = $audit->record($employee, 'PATCH', 'requests.leave', 'Updated a leave request', 'location-ip-1', $request);
        $secondId = $audit->record($employee, 'PATCH', 'requests.leave', 'Updated a leave request', 'location-ip-2', $request);

        $first = PortalLog::query()->find($firstId);
        $second = PortalLog::query()->find($secondId);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('Calamba City, Laguna, Philippines', $first->location_label);
        $this->assertSame('ip', $first->location_source);
        $this->assertSame($first->location_label, $second->location_label);
        Http::assertSentCount(1);

        Http::fake([
            'https://ipwho.is/1.1.1.1' => Http::response([], 503),
            'https://ipapi.co/1.1.1.1/json/' => Http::response([], 503),
        ]);
        $failedRequest = Request::create(
            '/api/development/portal/requests/leave/41',
            'PATCH',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '1.1.1.1'],
        );
        $failedId = $audit->record($employee, 'PATCH', 'requests.leave', 'Updated a leave request', 'location-ip-failed', $failedRequest);
        $failed = PortalLog::query()->find($failedId);
        $this->assertNotNull($failed);
        $this->assertNull($failed->location_label);
        $this->assertNull($failed->location_source);
    }
}
