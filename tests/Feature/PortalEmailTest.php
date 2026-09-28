<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalEmailMarkup;
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
        // The local database keeps real blocks, so the counts are read against what was already there.
        $before = $this->getJson(self::BASE.'/administration/email-blocks', $headers)->assertOk()->json('counts');

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

        $footer = $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'Standard signature',
            'subject' => 'Ignored, a footer has no subject',
            'body' => 'IKAIKA Bim Solutions',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.kind', 'footer')
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.subject', null)
            ->json('data');

        $listed = $this->getJson(self::BASE.'/administration/email-blocks?kind=template', $headers)
            ->assertOk()
            ->assertJsonPath('counts.templates', $before['templates'] + 1)
            ->assertJsonPath('counts.footers', $before['footers'] + 1)
            ->assertJsonPath('counts.total', $before['total'] + 2)
            ->json('data');
        $this->assertCount($before['templates'] + 1, $listed);
        $this->assertSame(['template'], array_values(array_unique(array_column($listed, 'kind'))));
        $this->assertContains('Holiday notice', array_column($listed, 'name'));

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

        $footerId = (int) $footer['id'];
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
        $noticesBefore = $this->emailNotices();

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

        $this->assertSame($noticesBefore + $expected, $this->emailNotices());
        $log = PortalLog::query()
            ->where('resource', 'administration.email')
            ->where('action', 'POST')
            ->latest('id')
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

    // A report reminder's notice opens the form the missing report is filed on.
    public function test_a_notice_can_open_a_page_every_member_can_reach_and_nothing_else(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->member();

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Reminder: report not yet filed',
            'body' => 'Please file it.',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
            'linkPath' => '/reports/late-report',
        ], $headers)->assertCreated();

        $notice = PortalNotification::query()
            ->where('employee_id', $member->getKey())
            ->where('type', PortalNotificationType::EMAIL_SENT)
            ->latest('id')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('/reports/late-report', $notice->link_path);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Look here',
            'body' => 'Go.',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
            'linkPath' => '/manage/user-management',
        ], $headers)->assertStatus(422);
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

    // All Emails narrows by days and status; its counts follow the days and category, never the status.
    public function test_the_outbox_narrows_by_days_status_and_category(): void
    {
        $headers = $this->authHeaders();
        // Years out, so the real outbox never falls inside the days asked for.
        $sent = $this->seedMessage('notice', 'sent', '2031-03-02 09:00:00');
        $partial = $this->seedMessage('notice', 'partial', '2031-03-05 18:30:00');
        $event = $this->seedMessage('event', 'failed', '2031-03-05 08:00:00');
        $this->seedMessage('notice', 'sent', '2031-04-01 09:00:00');
        $days = self::BASE.'/administration/emails?from=2031-03-01&to=2031-03-05';

        $all = $this->getJson($days, $headers)->assertOk();
        $this->assertSame([$partial, $event, $sent], array_map('intval', array_column($all->json('data'), 'id')));
        $all->assertJsonPath('counts', ['total' => 3, 'sent' => 1, 'partial' => 1, 'failed' => 1]);

        $failed = $this->getJson($days.'&status=failed', $headers)->assertOk();
        $this->assertSame([$event], array_map('intval', array_column($failed->json('data'), 'id')));
        $failed->assertJsonPath('counts.total', 3)->assertJsonPath('meta.total', 1);

        $this->getJson($days.'&category=notice', $headers)->assertOk()
            ->assertJsonPath('counts', ['total' => 2, 'sent' => 1, 'partial' => 1, 'failed' => 0]);
        $this->assertCount(1, $this->getJson(self::BASE.'/administration/emails?from=2031-03-05&to=2031-03-05&status=partial', $headers)->json('data'));
        // An unknown status is no filter at all; a malformed day is refused.
        $this->assertCount(3, $this->getJson($days.'&status=lost', $headers)->json('data'));
        $this->getJson(self::BASE.'/administration/emails?from=2031-02-30', $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/administration/emails?to=March', $headers)->assertStatus(422);
    }

    // The composer's preview is the very HTML a send puts in the inbox, footer included.
    public function test_the_preview_is_the_email_a_send_would_build(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $footerId = (int) $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'Preview sign-off',
            'body' => "Regards,\n**MIS**",
        ], $headers)->assertCreated()->json('data.id');
        $body = "Hello **team**,\nsee [the site](https://ikaika.com).\n\n- one\n- two";

        $html = $this->postJson(self::BASE.'/administration/emails/preview', ['body' => $body, 'footerId' => $footerId], $headers)
            ->assertOk()
            ->json('data.html');

        $this->assertIsString($html);
        $this->assertStringContainsString('<strong>team</strong>', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringContainsString('href="https://ikaika.com"', $html);
        $this->assertStringContainsString('<li style=', $html);
        $this->assertStringContainsString('<strong>MIS</strong>', $html);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Preview check',
            'body' => $body,
            'audience' => 'everyone',
            'footerId' => $footerId,
        ], $headers)->assertCreated();
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->render() === $html);

        // Nothing written yet is still a preview; a draft past a send's limit is refused, and a read is not a preview.
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => ''], $headers)->assertOk();
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => str_repeat('a', 20001)], $headers)->assertStatus(422);
        $this->getJson(self::BASE.'/administration/emails/preview', $headers)->assertStatus(405);
    }

    // Typed HTML and script links never reach an inbox as markup.
    public function test_the_markup_escapes_html_and_drops_unsafe_links(): void
    {
        // A footer of nothing but blank space adds no rule under the message.
        $html = PortalEmailMarkup::document('<b>hi</b> [x](javascript:alert(1)) ![p](https://e.test/p.png)', "  \n");

        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<hr', $html);
    }

    public function test_the_outbox_list_stays_skinny_and_one_message_carries_the_body(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $countsBefore = $this->getJson(self::BASE.'/administration/emails', $headers)->json('counts');

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'other',
            'subject' => 'Parking reminder',
            'body' => 'Visitors park at the rear.',
            'audience' => 'everyone',
        ], $headers)->assertCreated();

        $listed = $this->getJson(self::BASE.'/administration/emails', $headers)
            ->assertOk()
            ->assertJsonPath('counts.total', $countsBefore['total'] + 1)
            ->assertJsonPath('counts.sent', $countsBefore['sent'] + 1)
            ->json('data');
        $this->assertIsArray($listed);
        $row = collect($listed)->firstWhere('subject', 'Parking reminder');
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('body', $row);

        $this->getJson(self::BASE.'/administration/emails/'.$row['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.body', 'Visitors park at the rear.')
            ->assertJsonPath('data.format', 'markup')
            ->assertJsonPath('data.footerFormat', 'markup')
            ->assertJsonPath('data.html', PortalEmailMarkup::document('Visitors park at the rear.', null))
            ->assertJsonPath('data.failures', []);
    }

    // HTML mode is the administrator's own code: sent, previewed, stored and shown as written.
    public function test_an_html_email_goes_out_as_written_with_an_html_footer(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $footer = $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer',
            'name' => 'HTML signature',
            'body' => '<p style="color:#555">IKAIKA <b>MIS</b></p>',
            'format' => 'html',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.format', 'html')
            ->json('data');
        $body = '<table width="100%"><tr><td style="padding:8px">Hello <b>team</b></td></tr></table>';

        $html = $this->postJson(self::BASE.'/administration/emails/preview', [
            'body' => $body,
            'format' => 'html',
            'footerId' => $footer['id'],
        ], $headers)->assertOk()->json('data.html');
        $this->assertIsString($html);
        $this->assertStringContainsString($body, $html);
        $this->assertStringContainsString('<p style="color:#555">IKAIKA <b>MIS</b></p>', $html);

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'HTML check',
            'body' => $body,
            'format' => 'html',
            'audience' => 'everyone',
            'footerId' => $footer['id'],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.format', 'html')
            ->assertJsonPath('data.footerFormat', 'html')
            ->assertJsonPath('data.html', $html)
            ->json('data');
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->render() === $html);
        $this->assertSame('html', EmailMessage::query()->whereKey($sent['id'])->value('format'));

        // Past markup's limit is fine for HTML, up to the column's own; an unknown format is refused.
        $long = '<p>'.str_repeat('a', 30000).'</p>';
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => $long, 'format' => 'html'], $headers)->assertOk();
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => $long], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => str_repeat('a', 60001), 'format' => 'html'], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => 'x', 'format' => 'rtf'], $headers)->assertStatus(422);
        $this->patchJson(self::BASE.'/administration/email-blocks/'.$footer['id'], [
            'name' => 'HTML signature',
            'body' => '**MIS**',
            'format' => 'markup',
        ], $headers)->assertOk()->assertJsonPath('data.format', 'markup');
    }

    // A whole HTML document is the email: nothing wraps it, and the footer sits inside its body.
    public function test_a_whole_html_document_is_not_wrapped_and_takes_the_footer_inside(): void
    {
        $page = '<!doctype html><html><head><title>t</title></head><body><p>Hi</p></body></html>';

        $html = PortalEmailMarkup::document($page, '**MIS**', PortalEmailMarkup::FORMAT_HTML);

        $this->assertStringStartsWith('<!doctype html><html>', $html);
        $this->assertStringEndsWith('</body></html>', $html);
        $this->assertStringContainsString('<p>Hi</p><hr', $html);
        $this->assertStringContainsString('<strong>MIS</strong></p>'."\n".'</div></body>', $html);
        $this->assertSame('<html><p>x</p><hr', substr(PortalEmailMarkup::document('<html><p>x</p>', 'f', 'html'), 0, 17));
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
        $noticesBefore = $this->emailNotices();

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
        // Nobody was reached, so nobody was told an email arrived.
        $this->assertSame($noticesBefore, $this->emailNotices());
    }

    public function test_it_refuses_an_audience_it_cannot_reach_or_does_not_offer(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $messagesBefore = EmailMessage::query()->count();

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
        $this->assertSame($messagesBefore, EmailMessage::query()->count());
    }

    private function seedMessage(string $category, string $status, string $createdAt): int
    {
        return (int) EmailMessage::query()->getConnection()->table('email_messages')->insertGetId([
            'category' => $category,
            'subject' => 'Seeded '.$status,
            'body' => 'Seeded body',
            'audience' => 'everyone',
            'audience_filter' => json_encode(['departments' => [], 'roles' => [], 'memberIds' => []], JSON_THROW_ON_ERROR),
            'recipient_count' => 3,
            'sent_count' => $status === 'failed' ? 0 : 3,
            'failed_count' => $status === 'sent' ? 0 : 1,
            'status' => $status,
            'sent_by' => (int) Employee::query()->value('id'),
            'failures' => json_encode([], JSON_THROW_ON_ERROR),
            'date_sent' => $createdAt,
            'date_created' => $createdAt,
        ]);
    }

    private function emailNotices(): int
    {
        return PortalNotification::query()->where('type', PortalNotificationType::EMAIL_SENT)->count();
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
