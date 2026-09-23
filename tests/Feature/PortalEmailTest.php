<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalNotificationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

class PortalEmailTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const BASE = '/api/development/portal';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_lists_send_email_under_administration(): void
    {
        $sections = $this->getJson(self::BASE)
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);

        $administration = collect($sections)->firstWhere('name', 'administration');
        $this->assertIsArray($administration);

        $emails = collect($administration['resources'])->firstWhere('name', 'emails');
        $this->assertIsArray($emails);
        $this->assertTrue($emails['requires_admin']);
        $this->assertSame(self::BASE.'/administration/emails', $emails['url']);

        $blocks = collect($administration['resources'])->firstWhere('name', 'email-blocks');
        $this->assertIsArray($blocks);
        $this->assertTrue($blocks['requires_admin']);
        $this->assertSame(self::BASE.'/administration/email-blocks', $blocks['url']);
    }

    public function test_an_administrator_builds_a_template_and_a_footer(): void
    {
        $headers = $this->authHeaders();

        $template = $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'template',
            'name' => 'Holiday notice',
            'category' => 'event',
            'subject' => 'Office closed on Monday',
            'body' => 'The office is closed for the holiday.',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('resource', 'email-blocks')
            ->assertJsonPath('data.kind', 'template')
            ->assertJsonPath('data.category', 'event')
            ->json('data');

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'Standard signature',
            'subject' => 'Ignored, a footer has no subject',
            'body' => 'IKAIKA Bim Solutions',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.kind', 'footer')
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.subject', null);

        $listed = $this->getJson(self::BASE.'/administration/email-blocks?kind=template', $headers)
            ->assertOk()
            ->assertJsonPath('counts.templates', 1)
            ->assertJsonPath('counts.footers', 1)
            ->assertJsonPath('counts.total', 2)
            ->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame('Holiday notice', $listed[0]['name']);

        $createdLog = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'INSERT')
            ->where('record_id', (string) $template['id'])
            ->first();
        $this->assertNotNull($createdLog);
        $this->assertIsArray($createdLog->payload);
        $this->assertSame('template', $createdLog->payload['kind']);
        $this->assertSame('Holiday notice', $createdLog->payload['name']);
        $this->assertSame('event', $createdLog->payload['category']);
        $this->assertSame('Office closed on Monday', $createdLog->payload['subject']);

        $this->patchJson(self::BASE.'/administration/email-blocks/'.$template['id'], [
            'name' => 'Holiday notice',
            'category' => 'notice',
            'subject' => 'Office closed on Monday',
            'body' => 'The office is closed for the holiday. Enjoy the long weekend.',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.category', 'notice')
            ->assertJsonPath('data.body', 'The office is closed for the holiday. Enjoy the long weekend.');

        $updatedLog = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'PATCH')
            ->where('record_id', (string) $template['id'])
            ->first();
        $this->assertNotNull($updatedLog);
        $this->assertIsArray($updatedLog->payload);
        $this->assertSame('notice', $updatedLog->payload['category']);

        $footerId = (int) EmailBlock::query()->where('kind', 'footer')->value('id');
        $this->deleteJson(self::BASE.'/administration/email-blocks/'.$footerId, [], $headers)
            ->assertNoContent();
        $this->assertNull(EmailBlock::query()->find($footerId));

        $deletedLog = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'DELETE')
            ->where('record_id', (string) $footerId)
            ->first();
        $this->assertNotNull($deletedLog);
        $this->assertIsArray($deletedLog->payload);
        $this->assertSame('footer', $deletedLog->payload['kind']);
        $this->assertSame('Standard signature', $deletedLog->payload['name']);
    }

    public function test_a_block_write_is_validated(): void
    {
        $headers = $this->authHeaders();

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'template',
            'category' => 'notice',
            'subject' => 'No name',
            'body' => 'Body',
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'notice',
            'name' => 'Wrong kind',
            'body' => 'Body',
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'template',
            'name' => 'Wrong category',
            'category' => 'spam',
            'subject' => 'Subject',
            'body' => 'Body',
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'No body',
            'body' => '   ',
        ], $headers)->assertStatus(422);
    }

    public function test_a_member_cannot_send_or_build_templates(): void
    {
        $headers = $this->headersFor($this->member());

        $this->getJson(self::BASE.'/administration/emails', $headers)->assertForbidden();
        $this->getJson(self::BASE.'/administration/emails/audiences', $headers)->assertForbidden();
        $this->getJson(self::BASE.'/administration/email-blocks', $headers)->assertForbidden();
        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Hi',
            'body' => 'Hello',
            'audience' => 'everyone',
        ], $headers)->assertForbidden();
    }

    public function test_the_audience_options_offer_the_active_roster(): void
    {
        $data = $this->getJson(self::BASE.'/administration/emails/audiences', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('resource', 'email-audiences')
            ->json('data');
        $this->assertIsArray($data);

        $this->assertSame($this->reachableCount(), $data['total']);
        $this->assertGreaterThan(0, $data['total']);
        $this->assertSame(
            ['everyone', 'department', 'role', 'members'],
            array_column($data['audiences'], 'value'),
        );
        $this->assertContains('notice', $data['categories']);
        $this->assertContains('Engineering', array_column($data['departments'], 'value'));
        $this->assertContains('Admin', array_column($data['roles'], 'value'));
        $this->assertNotEmpty($data['members']);
    }

    public function test_it_sends_to_everyone_and_leaves_an_outbox_row_and_a_bell_row(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $expected = $this->reachableCount();

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Portal maintenance tonight',
            'body' => 'The portal goes down at 10pm for an hour.',
            'audience' => 'everyone',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('resource', 'emails')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipientCount', $expected)
            ->assertJsonPath('data.sentCount', $expected)
            ->assertJsonPath('data.failedCount', 0)
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, $expected);

        $row = EmailMessage::query()->findOrFail($sent['id']);
        $this->assertSame('sent', $row->status);
        $this->assertSame($expected, (int) $row->sent_count);
        $this->assertSame(0, (int) $row->failed_count);
        $this->assertNotNull($row->date_sent);

        $this->assertSame(
            $expected,
            PortalNotification::query()->where('type', PortalNotificationType::EMAIL_SENT)->count(),
        );
        $log = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'POST')
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame('notice', $log->payload['category']);
        $this->assertSame('Portal maintenance tonight', $log->payload['subject']);
        $this->assertSame('everyone', $log->payload['audience']);
        $this->assertSame($expected, $log->payload['recipientCount']);
        $this->assertSame($expected, $log->payload['sent']);
        $this->assertSame(0, $log->payload['failed']);
        $this->assertArrayNotHasKey('body', $log->payload);
    }

    public function test_it_sends_to_a_department_and_keeps_the_footer_it_used(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();

        $footerId = (int) $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'Safety line',
            'body' => 'Report hazards to your supervisor.',
        ], $headers)->assertCreated()->json('data.id');

        $expected = $this->reachableCount('department', 'Engineering');

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'complaint',
            'subject' => 'Site safety briefing',
            'body' => 'A briefing is scheduled for Friday.',
            'audience' => 'department',
            'departments' => ['Engineering'],
            'footerId' => $footerId,
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.audience', 'department')
            ->assertJsonPath('data.audienceFilter.departments', ['Engineering'])
            ->assertJsonPath('data.footerId', $footerId)
            ->assertJsonPath('data.footerBody', 'Report hazards to your supervisor.')
            ->assertJsonPath('data.recipientCount', $expected)
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, $expected);

        $log = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'POST')
            ->where('record_id', (string) $sent['id'])
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame('complaint', $log->payload['category']);
        $this->assertSame('department', $log->payload['audience']);
        $this->assertSame(['Engineering'], $log->payload['audienceFilter']['departments']);
        $this->assertSame($expected, $log->payload['recipientCount']);

        // Deleting the footer afterwards must not rewrite what was already sent.
        $this->deleteJson(self::BASE.'/administration/email-blocks/'.$footerId, [], $headers)
            ->assertNoContent();

        $this->getJson(self::BASE.'/administration/emails/'.$sent['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.footerId', null)
            ->assertJsonPath('data.footerBody', 'Report hazards to your supervisor.')
            ->assertJsonPath('data.body', 'A briefing is scheduled for Friday.');
    }

    public function test_the_outbox_list_stays_skinny_and_one_message_carries_the_body(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'other',
            'subject' => 'Parking reminder',
            'body' => 'Visitors park at the rear.',
            'audience' => 'everyone',
        ], $headers)->assertCreated();

        $listed = $this->getJson(self::BASE.'/administration/emails', $headers)
            ->assertOk()
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('counts.sent', 1)
            ->json('data');
        $this->assertIsArray($listed);
        $this->assertArrayNotHasKey('body', $listed[0]);
        $this->assertSame('Parking reminder', $listed[0]['subject']);

        $this->getJson(self::BASE.'/administration/emails/'.$listed[0]['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.body', 'Visitors park at the rear.')
            ->assertJsonPath('data.failures', []);
    }

    public function test_a_member_who_cannot_be_reached_is_recorded_by_name_and_not_by_address(): void
    {
        $unreachable = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($unreachable);
        $address = trim((string) $unreachable->email);
        $memberId = (int) $unreachable->getKey();

        Mail::shouldReceive('to')->andReturnUsing(function ($to) use ($address) {
            $pending = Mockery::mock();
            if (is_string($to) && trim($to) === $address) {
                $pending->shouldReceive('send')->andThrow(
                    new RuntimeException('550 mailbox unavailable for '.$address),
                );
            } else {
                $pending->shouldReceive('send')->andReturnNull();
            }

            return $pending;
        });

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'event',
            'subject' => 'Town hall',
            'body' => 'The town hall starts at 3pm.',
            'audience' => 'members',
            'memberIds' => [$memberId],
        ], $this->authHeaders());

        // The only recipient is the address the transport refuses, so nothing was delivered.
        $sent->assertStatus(201)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.sentCount', 0)
            ->assertJsonPath('data.failedCount', 1);

        $failure = $sent->json('data.failures.0');
        $this->assertIsArray($failure);
        $this->assertSame($memberId, $failure['employeeId']);
        $this->assertStringContainsString('[address hidden]', $failure['reason']);

        $stored = json_encode(EmailMessage::query()->orderByDesc('id')->firstOrFail()->failures);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString($address, $stored);
        $this->assertStringNotContainsString('@', $stored);
        $this->assertSame(0, PortalNotification::query()
            ->where('type', PortalNotificationType::EMAIL_SENT)
            ->count());
    }

    public function test_it_refuses_an_audience_it_cannot_reach_or_does_not_offer(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Nobody',
            'body' => 'Body',
            'audience' => 'nobody',
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'spam',
            'subject' => 'Wrong category',
            'body' => 'Body',
            'audience' => 'everyone',
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Unknown member',
            'body' => 'Body',
            'audience' => 'members',
            'memberIds' => [999999],
        ], $headers)->assertStatus(422);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Unknown footer',
            'body' => 'Body',
            'audience' => 'everyone',
            'footerId' => 999999,
        ], $headers)->assertStatus(422);

        $templateId = (int) $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'template',
            'name' => 'Not a footer',
            'category' => 'notice',
            'subject' => 'Subject',
            'body' => 'Body',
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Template as footer',
            'body' => 'Body',
            'audience' => 'everyone',
            'footerId' => $templateId,
        ], $headers)->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailMessage::query()->count());
    }

    private function reachableCount(?string $audience = null, ?string $value = null): int
    {
        $query = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('email')
            ->where('email', '!=', '');
        if ($audience === 'department') {
            $query->whereRaw('LOWER(TRIM(department)) = ?', [strtolower((string) $value)]);
        }

        $count = 0;
        foreach ($query->get() as $employee) {
            if (filter_var(trim((string) $employee->email), FILTER_VALIDATE_EMAIL) !== false) {
                $count++;
            }
        }

        return $count;
    }
}
