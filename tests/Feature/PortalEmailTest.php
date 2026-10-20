<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalDepartmentMailboxes;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalMailDiagnostics;
use App\Support\Portal\PortalNotificationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\SentMessage;
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

    private string $diagnostics;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Send test writes a report; a test must never add to the real one.
        $this->diagnostics = tempnam(sys_get_temp_dir(), 'mail-test-');
        config(['mail.diagnostics_path' => $this->diagnostics]);
    }

    protected function tearDown(): void
    {
        @unlink($this->diagnostics);
        parent::tearDown();
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

    // Every member may write and send an email as themselves (2026-10-15), but the templates-and-footers
    // library and the All Emails list stay an administrator's.
    public function test_a_member_can_send_as_themselves_but_not_read_all_emails_or_the_library(): void
    {
        Mail::fake();
        $member = $this->member();
        $member->forceFill(['email' => 'member@ikaikabim.com'])->save();
        $headers = $this->headersFor($member);

        // All Emails is an administrator's list; the composer, its audiences, are the member's.
        $this->getJson(self::BASE.'/administration/emails', $headers)->assertForbidden();
        $this->getJson(self::BASE.'/administration/emails/audiences', $headers)->assertOk();

        // Building the library stays behind email.library.
        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'footer', 'name' => 'Mine', 'body' => 'Bye',
        ], $headers)->assertForbidden();

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice', 'subject' => 'Hi', 'body' => 'Hello', 'audience' => 'everyone', 'asSender' => true,
        ], $headers)->assertCreated();
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

        // Each member carries their work address for the composer's suggestions, never a personal one.
        $member = $data['members'][0];
        $this->assertSame(trim((string) Employee::query()->whereKey($member['id'])->value('email')), $member['email']);
        $this->assertSame(['id', 'name', 'department', 'email'], array_keys($member));
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
        // The log is written when the copies are queued; how each fared lives on the outbox row.
        $this->assertSame($expected, $log->payload['copies']);
        $this->assertArrayNotHasKey('sent', $log->payload);
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

    // All Emails lists who a send went to; a send from before the list was kept says so with null.
    public function test_a_send_keeps_who_it_went_to_by_name_and_address(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->member();

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Who got this',
            'body' => 'Hello.',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
        ], $headers)->assertCreated()->json('data');

        $this->getJson(self::BASE.'/administration/emails/'.$sent['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.recipients', [[
                'name' => trim($member->first_name.' '.$member->last_name),
                'email' => trim((string) $member->email),
            ]]);

        EmailMessage::query()->whereKey($sent['id'])->update(['recipients' => null]);
        Cache::flush();
        $this->getJson(self::BASE.'/administration/emails/'.$sent['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.recipients', null);
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
        $all->assertJsonPath('counts', ['total' => 3, 'sent' => 1, 'partial' => 1, 'failed' => 1, 'sending' => 0]);

        $failed = $this->getJson($days.'&status=failed', $headers)->assertOk();
        $this->assertSame([$event], array_map('intval', array_column($failed->json('data'), 'id')));
        $failed->assertJsonPath('counts.total', 3)->assertJsonPath('meta.total', 1);

        $this->getJson($days.'&category=notice', $headers)->assertOk()
            ->assertJsonPath('counts', ['total' => 2, 'sent' => 1, 'partial' => 1, 'failed' => 0, 'sending' => 0]);
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

    // The Footers editor previews a footer before it is saved, in its own format, under the message.
    public function test_the_preview_builds_a_draft_footer_as_it_would_send(): void
    {
        $headers = $this->authHeaders();

        $markup = $this->postJson(self::BASE.'/administration/emails/preview', [
            'body' => 'Your message',
            'footerBody' => "Regards,\n**MIS**",
        ], $headers)->assertOk()->json('data.html');
        $this->assertSame(PortalEmailMarkup::document('Your message', "Regards,\n**MIS**"), $markup);

        $html = $this->postJson(self::BASE.'/administration/emails/preview', [
            'body' => 'Your message',
            'footerBody' => '<p style="color:red">Legal</p>',
            'footerFormat' => 'html',
        ], $headers)->assertOk()->json('data.html');
        $this->assertStringContainsString('<p style="color:red">Legal</p>', (string) $html);

        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => 'x', 'footerBody' => str_repeat('a', 20001)], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => 'x', 'footerBody' => 'x', 'footerFormat' => 'rtf'], $headers)->assertStatus(422);
    }

    // A picture in markup is capped to the message width, so a large one never overflows the email.
    public function test_a_markup_image_is_capped_to_the_message_width(): void
    {
        $html = PortalEmailMarkup::document('![Banner](https://res.cloudinary.test/banner.png)', null);

        $this->assertStringContainsString('<img style="display:block;max-width:100%;height:auto;border:0" src="https://res.cloudinary.test/banner.png" alt="Banner"', $html);
    }

    // Every portal email asks for Inter and falls back through a stack; a whole document keeps its own fonts.
    public function test_the_mail_asks_for_inter_with_a_fallback_stack(): void
    {
        $html = PortalEmailMarkup::document('Hello', 'Regards');

        $this->assertStringContainsString('family=Inter:wght@400;600;700', $html);
        $this->assertStringContainsString('font-family:'.PortalEmailMarkup::FONT_STACK, $html);
        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', PortalEmailMarkup::document('<html><body>x</body></html>', null, 'html'));
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

    // A whole document can say where the footer goes; without one, the slot stays, unseen.
    public function test_a_whole_document_takes_the_footer_where_it_marks_it(): void
    {
        $page = '<html><body><div>'.PortalEmailMarkup::FOOTER_SLOT.'</div><p>After</p></body></html>';

        $this->assertSame('<html><body><div><hr', substr(PortalEmailMarkup::document($page, '**MIS**', 'html'), 0, 20));
        $this->assertStringContainsString('</div></div><p>After</p></body>', PortalEmailMarkup::document($page, '**MIS**', 'html'));
        $this->assertSame($page, PortalEmailMarkup::document($page, null, 'html'));
    }

    // The reported ask: a letter that looks the same whether it is written in Formatted or in HTML.
    public function test_a_letter_is_its_markup_in_the_letter_design_and_its_html_sends_the_same(): void
    {
        $markup = "Dear **Team**,\n\nWe meet at [Venue].\n\n- [First topic]";

        $letter = PortalEmailMarkup::document($markup, "Regards,\n**MIS**", PortalEmailMarkup::FORMAT_LETTER);

        $this->assertSame('letter', PortalEmailMarkup::format(' Letter '));
        $this->assertStringStartsWith('<!DOCTYPE html>', $letter);
        $this->assertStringContainsString('IKAIKA BIM</div>', $letter);
        $this->assertStringContainsString('<strong>Team</strong>', $letter);
        $this->assertStringContainsString('We meet at [Venue].', $letter);
        $this->assertStringContainsString('<li style="margin:0 0 4px">[First topic]</li>', $letter);
        // The footer sits at the foot of the sheet, inside the design rather than under it.
        $this->assertStringNotContainsString(PortalEmailMarkup::FOOTER_SLOT, $letter);
        $this->assertMatchesRegularExpression(
            '#<!-- Footer:.*?<td[^>]*>\s*<hr[^>]*>.*<strong>MIS</strong>.*?</td>\s*</tr>\s*</table>\s*</div>\s*<!--\[if mso\]></td></tr></table><!\[endif\]-->\s*</td>\s*</tr>\s*</table>\s*</body>#s',
            $letter,
        );

        // Its HTML is the design itself, footer slot and all, and sent as HTML it is the same email.
        $html = PortalEmailMarkup::document($markup, null, PortalEmailMarkup::FORMAT_LETTER);
        $this->assertStringContainsString(PortalEmailMarkup::FOOTER_SLOT, $html);
        $this->assertSame($letter, PortalEmailMarkup::document($html, "Regards,\n**MIS**", PortalEmailMarkup::FORMAT_HTML));
    }

    // From: IKAIKA BIM sends as the company, with no one to reply to, under its own letterhead; every category the composer offers is taken.
    public function test_an_email_goes_out_as_the_company_in_any_offered_category(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->administrator();

        foreach (['reminder', 'request', 'project', 'security', 'warning'] as $category) {
            $sent = $this->postJson(self::BASE.'/administration/emails', [
                'category' => $category,
                'subject' => 'Company '.$category,
                'body' => 'From the company.',
                'format' => 'letter',
                'audience' => 'members',
                'memberIds' => [(int) $member->getKey()],
                'sender' => 'company',
            ], $headers)->assertCreated()->assertJsonPath('data.category', $category);

            $row = EmailMessage::query()->findOrFail($sent->json('data.id'));
            $this->assertSame('IKAIKA BIM', $row->from_name);
            $this->assertNull($row->reply_to);
        }

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->subjectLine === 'Company reminder'
            && $mail->fromName === 'IKAIKA BIM'
            && $mail->replyToAddress === null
            && $mail->envelope()->subject === '[Reminder] Company reminder'
            && ! str_contains($mail->render(), 'IKAIKA PORTAL'));

        // The preview draws the same letterhead when told the email is the company's.
        $html = $this->postJson(self::BASE.'/administration/emails/preview', ['body' => 'Hi', 'format' => 'letter', 'sender' => 'company'], $headers)
            ->assertOk()->json('data.html');
        $this->assertStringNotContainsString('IKAIKA PORTAL', (string) $html);
        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'gossip', 'subject' => 'x', 'body' => 'x', 'audience' => 'everyone',
        ], $headers)->assertStatus(422);
    }

    public function test_a_letter_is_previewed_sent_and_saved_as_a_letter(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $body = "Dear Team,\n\nThe office is closed on **Friday**.";

        // A letter carries its category and subject over the message, in the preview as in the send.
        $html = $this->postJson(self::BASE.'/administration/emails/preview', [
            'body' => $body,
            'format' => 'letter',
            'category' => 'notice',
            'subject' => 'Closed on Friday',
        ], $headers)->assertOk()->json('data.html');
        $this->assertSame(PortalEmailMarkup::document($body, null, 'letter', 'markup', 'notice', 'Closed on Friday'), $html);
        $this->assertStringContainsString('>Notice</td>', $html);
        $this->assertStringContainsString('>Closed on Friday</h1>', $html);

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Closed on Friday',
            'body' => $body,
            'format' => 'letter',
            'audience' => 'everyone',
        ], $headers)->assertCreated()->assertJsonPath('data.format', 'letter');
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->render() === $html
            && $mail->envelope()->subject === '[Notice] Closed on Friday');

        $this->postJson(self::BASE.'/administration/email-blocks', [
            'kind' => 'template',
            'name' => 'Closure letter',
            'category' => 'notice',
            'subject' => 'Closed on Friday',
            'body' => $body,
            'format' => 'letter',
        ], $headers)->assertCreated()->assertJsonPath('data.format', 'letter');

        // A letter is held to markup's limit, not HTML's.
        $this->postJson(self::BASE.'/administration/emails/preview', ['body' => str_repeat('a', 20001), 'format' => 'letter'], $headers)->assertStatus(422);
    }

    // Typed addresses go as one real email with its Cc and Bcc, as Outlook sends it; no member is told.
    public function test_a_custom_audience_sends_one_email_with_real_cc_and_bcc(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $noticesBefore = $this->emailNotices();

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Supplier notice',
            'body' => 'Deliveries move to Monday.',
            'audience' => 'custom',
            'emails' => ['Alpha@Supplier.test', 'beta@supplier.test', 'alpha@supplier.test '],
            'cc' => ['boss@ikaika.test'],
            'bcc' => ['audit@ikaika.test'],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.audience', 'custom')
            ->assertJsonPath('data.recipientCount', 2)
            ->assertJsonPath('data.sentCount', 2)
            ->assertJsonPath('data.audienceFilter.emails', ['alpha@supplier.test', 'beta@supplier.test'])
            ->assertJsonPath('data.cc', ['boss@ikaika.test'])
            ->assertJsonPath('data.bcc', ['audit@ikaika.test'])
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, 1);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('alpha@supplier.test')
            && $mail->hasTo('beta@supplier.test')
            && $mail->hasCc('boss@ikaika.test')
            && $mail->hasBcc('audit@ikaika.test'));
        $this->assertSame($noticesBefore, $this->emailNotices());

        // The ledger that syncs out counts the typed addresses and never carries them.
        $log = PortalLog::query()->where('resource', 'administration.email')->where('record_id', (string) $sent['id'])->firstOrFail();
        $this->assertStringNotContainsString('supplier.test', (string) json_encode($log->payload));
        $this->assertStringNotContainsString('boss@', (string) json_encode($log->payload));

        $this->getJson(self::BASE.'/administration/emails/'.$sent['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.recipients', [
                ['name' => 'alpha@supplier.test', 'email' => 'alpha@supplier.test'],
                ['name' => 'beta@supplier.test', 'email' => 'beta@supplier.test'],
            ])
            ->assertJsonPath('data.cc', ['boss@ikaika.test'])
            ->assertJsonPath('data.bcc', ['audit@ikaika.test']);

        // Only BCC: still the one email, to the typed address with the hidden copy riding along.
        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Quiet copy',
            'body' => 'x',
            'audience' => 'custom',
            'emails' => ['gamma@supplier.test'],
            'bcc' => ['audit@ikaika.test'],
        ], $headers)->assertCreated()->assertJsonPath('data.cc', []);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('gamma@supplier.test') && $mail->hasBcc('audit@ikaika.test'));
    }

    public function test_typed_addresses_are_validated_before_anything_is_sent(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $draft = ['category' => 'notice', 'subject' => 'x', 'body' => 'x', 'audience' => 'custom'];

        $this->postJson(self::BASE.'/administration/emails', $draft, $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'emails' => ['not-an-address']], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [
            ...$draft,
            'emails' => array_map(static fn (int $i): string => 'p'.$i.'@supplier.test', range(1, 51)),
        ], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'emails' => ['a@supplier.test'], 'cc' => ['nope']], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [
            ...$draft,
            'emails' => ['a@supplier.test'],
            'bcc' => array_map(static fn (int $i): string => 'b'.$i.'@supplier.test', range(1, 21)),
        ], $headers)->assertStatus(422);

        Mail::assertNothingSent();
    }

    // A member audience keeps private copies, so a failed CC and BCC copy is its own row, not the send's.
    public function test_a_failed_cc_and_bcc_copy_on_a_member_send_is_recorded_without_failing_it(): void
    {
        $member = $this->administrator();
        Mail::shouldReceive('to')->andReturnUsing(function ($to) {
            $pending = Mockery::mock();
            $pending->shouldReceive('cc')->andReturnSelf();
            $pending->shouldReceive('bcc')->andReturnSelf();
            if ($to === ['boss@ikaika.test']) {
                $pending->shouldReceive('send')->andThrow(new RuntimeException('550 relay denied for boss@ikaika.test'));
            } else {
                $pending->shouldReceive('send')->andReturnNull();
            }

            return $pending;
        });

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Copy check',
            'body' => 'x',
            'audience' => 'members',
            'memberIds' => [(int) $member->getKey()],
            'cc' => ['boss@ikaika.test'],
        ], $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.failures.0.name', 'CC and BCC copy');
        $this->assertStringContainsString('[address hidden]', (string) $sent->json('data.failures.0.reason'));
    }

    // One Custom email either goes or does not, so a refusal marks every typed address it carried.
    public function test_a_refused_custom_email_marks_each_typed_address_with_the_reason(): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function () {
            $pending = Mockery::mock();
            $pending->shouldReceive('cc')->andReturnSelf();
            $pending->shouldReceive('bcc')->andReturnSelf();
            $pending->shouldReceive('send')->andThrow(new RuntimeException('550 rejected for alpha@supplier.test'));

            return $pending;
        });

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Refused',
            'body' => 'x',
            'audience' => 'custom',
            'emails' => ['alpha@supplier.test', 'beta@supplier.test'],
            'cc' => ['boss@ikaika.test'],
        ], $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.sentCount', 0)
            ->assertJsonPath('data.failedCount', 2)
            ->assertJsonPath('data.failures.0.name', 'alpha@supplier.test')
            ->assertJsonPath('data.failures.1.name', 'beta@supplier.test')
            ->assertJsonPath('data.failures.1.reason', '550 rejected for [address hidden]');
    }

    // Send as me: the portal's own address carries the sender's name, and replies go to them.
    public function test_an_administrator_can_send_as_themselves_through_the_portal_address(): void
    {
        Mail::fake();
        $admin = $this->administrator();
        $email = strtolower(trim((string) $admin->email));
        $name = PortalActivityCopy::displayName($admin);
        $draft = [
            'category' => 'notice',
            'subject' => 'From me',
            'body' => 'x',
            'audience' => 'custom',
            'emails' => ['alpha@supplier.test'],
        ];

        $sent = $this->postJson(self::BASE.'/administration/emails', [...$draft, 'asSender' => true], $this->headersFor($admin))
            ->assertCreated()
            ->assertJsonPath('data.fromName', $name)
            ->assertJsonPath('data.replyTo', $email)
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, function (PortalEmailMail $mail) use ($name, $email): bool {
            $envelope = $mail->envelope();

            // As Me without their own address: the portal's own address, their name, replies to them.
            return $envelope->from?->address === config('mail.from.address')
                && $envelope->from->name === $name
                && ($envelope->replyTo[0]->address ?? null) === $email;
        });
        $this->assertSame($email, EmailMessage::query()->whereKey($sent['id'])->value('reply_to'));

        // Off, the mail is the portal's own: no name of its own and nowhere else for replies.
        $this->postJson(self::BASE.'/administration/emails', $draft, $this->headersFor($admin))
            ->assertCreated()
            ->assertJsonPath('data.fromName', null)
            ->assertJsonPath('data.replyTo', null);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->envelope()->from === null && $mail->envelope()->replyTo === []);
        // An account with no address has nowhere for replies to go, so the switch is refused.
        $headers = $this->headersFor($admin);
        $admin->forceFill(['email' => ''])->save();
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'asSender' => true], $headers)->assertStatus(422);
    }

    // In Developer mode the mail never names who stood behind it, nor where replies would reach them.
    public function test_a_developer_sending_as_themselves_goes_out_as_a_developer_with_no_reply_address(): void
    {
        Mail::fake();
        $admin = $this->administrator();
        $admin->forceFill(['is_developer' => 1])->save();
        $token = $this->developerModeOn((string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $admin->id_no])->json('token'));

        $this->withToken($token)->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Quiet',
            'body' => 'x',
            'audience' => 'custom',
            'emails' => ['alpha@supplier.test'],
            'asSender' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.fromName', 'A developer')
            ->assertJsonPath('data.replyTo', null);
    }

    public function test_the_server_acceptance_line_is_read_without_the_login(): void
    {
        $sent = Mockery::mock(SentMessage::class);
        $sent->shouldReceive('getDebug')->andReturn("> AUTH LOGIN\n< 334 VXNlcm5hbWU6\n> bm9yZXBseQ==\n< 235 Authentication succeeded\n> DATA\n< 250 OK id=1abcDE-000123-XY\n");

        $this->assertSame('250 OK id=1abcDE-000123-XY', PortalEmailMessages::acceptance($sent));
        $this->assertNull(PortalEmailMessages::acceptance(null));
    }

    // The report says everything that decides a send except the password, and the login never shows.
    public function test_the_mail_report_shows_the_settings_and_never_the_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.example.test',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.username' => 'noreply@example.test',
            'mail.mailers.smtp.password' => 'Zq9#SecretValue',
            'mail.mailers.smtp.local_domain' => '127.0.0.1',
        ]);
        $sent = Mockery::mock(SentMessage::class);
        $sent->shouldReceive('getDebug')->andReturn("< 220 ready\n> EHLO [127.0.0.1]\n> AUTH LOGIN\n< 334 VXNlcm5hbWU6\n> bm9yZXBseQ==\n< 334 UGFzc3dvcmQ6\n> WnE5I1NlY3JldFZhbHVl\n< 235 Authentication succeeded\n> DATA\n< 250 OK id=1abc\n");
        $sent->shouldReceive('getMessageId')->andReturn('m1@example.test');

        PortalMailDiagnostics::record('unit', 'jane@example.test', $sent, null);
        $report = (string) file_get_contents($this->diagnostics);

        $this->assertStringContainsString('host           : mail.example.test', $report);
        $this->assertStringContainsString('EHLO domain    : 127.0.0.1', $report);
        $this->assertStringContainsString('password       : set, 15 characters, contains #', $report);
        $this->assertStringContainsString('result         : accepted 250 OK id=1abc', $report);
        $this->assertStringContainsString('> AUTH LOGIN [redacted]', $report);
        $this->assertStringNotContainsString('Zq9#SecretValue', $report);
        $this->assertStringNotContainsString('WnE5I1NlY3JldFZhbHVl', $report);
        $this->assertStringNotContainsString('bm9yZXBseQ==', $report);

        PortalMailDiagnostics::record('unit', 'jane@example.test', null, new RuntimeException('Connection refused'));
        $this->assertStringContainsString('FAILED RuntimeException: Connection refused', (string) file_get_contents($this->diagnostics));
    }

    public function test_the_password_shape_names_the_usual_env_mistakes(): void
    {
        $this->assertSame('NOT SET', PortalMailDiagnostics::passwordShape(null));
        $this->assertSame('NOT SET', PortalMailDiagnostics::passwordShape(''));
        $this->assertSame('set, 6 characters', PortalMailDiagnostics::passwordShape('abcdef'));
        $this->assertStringContainsString('SPACE at an end', PortalMailDiagnostics::passwordShape('abc '));
        $this->assertStringContainsString('QUOTE kept at an end', PortalMailDiagnostics::passwordShape("'abc'"));
        $this->assertSame("> AUTH PLAIN [redacted]\n< 235 ok", str_replace("\r\n", "\n", PortalMailDiagnostics::redacted("> AUTH PLAIN AGZvbwBiYXI=\n< 235 ok")));
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
            $pending->shouldReceive('cc')->andReturnSelf();
            $pending->shouldReceive('bcc')->andReturnSelf();
            if ($to === [$address]) {
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

    /*
     * A queued copy is rebuilt from its row, so the From address a "send as me" chose is stored on the
     * row and read back here. The company names itself on the portal's own address, and the portal
     * leaves the default in place.
     */
    public function test_a_stored_email_leaves_from_its_own_address_only_when_the_row_carries_one(): void
    {
        config(['mail.from.address' => 'noreply@ikaikabim.com']);
        $row = (object) [
            'subject' => 'Hi', 'body' => 'Hello', 'footer_body' => null,
            'format' => 'markup', 'footer_format' => 'markup',
            'from_name' => 'Jane Doe', 'reply_to' => 'jane@ikaikabim.com', 'from_address' => 'jane@ikaikabim.com',
            'category' => 'notice',
        ];

        $signed = PortalEmailMessages::mailFromRow($row)->envelope();
        $this->assertSame('jane@ikaikabim.com', $signed->from?->address);
        $this->assertSame('Jane Doe', $signed->from?->name);

        // No stored address (a plain Me, or any other sender): the portal's own address, name still theirs.
        $row->from_address = null;
        $this->assertSame('noreply@ikaikabim.com', PortalEmailMessages::mailFromRow($row)->envelope()->from?->address);

        // The company names itself on the portal's address; the portal leaves the default in place.
        $row->from_name = PortalEmailMarkup::COMPANY_SENDER;
        $this->assertSame('noreply@ikaikabim.com', PortalEmailMessages::mailFromRow($row)->envelope()->from?->address);

        $row->from_name = null;
        $this->assertNull(PortalEmailMessages::mailFromRow($row)->envelope()->from);
    }

    // "Send as me" may leave from the person's own address; an address off the domain is refused.
    public function test_sending_as_yourself_from_your_own_address_stores_it_and_refuses_off_domain(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'noreply@ikaikabim.com']);
        $admin = $this->administrator();
        $admin->forceFill(['email' => 'ada@ikaikabim.com'])->save();
        $draft = [
            'category' => 'notice', 'subject' => 'From me', 'body' => 'x',
            'audience' => 'custom', 'emails' => ['alpha@supplier.test'],
        ];

        $sent = $this->postJson(self::BASE.'/administration/emails', [...$draft, 'asSender' => true, 'ownAddress' => true], $this->headersFor($admin))
            ->assertCreated()
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, function (PortalEmailMail $mail) use ($admin): bool {
            return $mail->envelope()->from?->address === 'ada@ikaikabim.com'
                && $mail->envelope()->from->name === PortalActivityCopy::displayName($admin);
        });
        $this->assertSame('ada@ikaikabim.com', EmailMessage::query()->whereKey($sent['id'])->value('from_address'));

        // Off the domain: refused, never silently sent from the portal's address as if it were theirs.
        $admin->forceFill(['email' => 'ada@gmail.com'])->save();
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'asSender' => true, 'ownAddress' => true], $this->headersFor($admin))
            ->assertStatus(422);
    }

    /*
     * The From is always the signed-in person's own. An address in the body is ignored -- the request
     * carries only the ownAddress toggle -- so nobody can send as another, which is what keeps a
     * tenant-wide Graph Mail.Send permission safe to hold.
     */
    public function test_nobody_can_send_as_someone_elses_address(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'noreply@ikaikabim.com']);
        $admin = $this->administrator();
        $admin->forceFill(['email' => 'ada@ikaikabim.com'])->save();

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice', 'subject' => 'Not me', 'body' => 'x',
            'audience' => 'custom', 'emails' => ['alpha@supplier.test'],
            'asSender' => true, 'ownAddress' => true,
            // A body asking for another address has no effect; there is no From field to read.
            'from' => 'someone.else@ikaikabim.com',
            'fromAddress' => 'someone.else@ikaikabim.com',
        ], $this->headersFor($admin))->assertCreated()->json('data');

        $this->assertSame('ada@ikaikabim.com', EmailMessage::query()->whereKey($sent['id'])->value('from_address'));
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->envelope()->from?->address === 'ada@ikaikabim.com');
    }

    // A department's own From (2026-10-19, user): an MIS administrator sends as dev@, an Engineering one as
    // engineering@, read from their own department; anyone else is refused rather than sent from noreply.
    public function test_an_administrator_sends_from_their_department_address_and_others_cannot(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'noreply@ikaikabim.com']);
        $admin = $this->administrator();
        $draft = [
            'category' => 'notice', 'subject' => 'From the team', 'body' => 'x',
            'audience' => 'custom', 'emails' => ['alpha@supplier.test'], 'sender' => 'department',
        ];

        foreach (['MIS' => ['IKAIKA MIS', 'dev@ikaikabim.com'], 'Engineering' => ['IKAIKA Engineering', 'engineering@ikaikabim.com']] as $department => [$name, $address]) {
            $admin->forceFill(['department' => $department, 'role' => 'Admin'])->save();
            $sent = $this->postJson(self::BASE.'/administration/emails', $draft, $this->headersFor($admin))->assertCreated()->json('data');

            Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->envelope()->from?->address === $address
                && $mail->envelope()->from->name === $name);
            $this->assertSame($address, EmailMessage::query()->whereKey($sent['id'])->value('from_address'));
        }

        // The session tells the composer which mailbox to offer.
        $this->assertSame(['name' => 'IKAIKA Engineering', 'address' => 'engineering@ikaikabim.com'],
            $this->postJson(self::BASE.'/auth/login', ['id_no' => $admin->id_no])->json('employee.department_sender'));

        // A department without a mailbox, and a non-administrator, are refused.
        $admin->forceFill(['department' => 'Finance'])->save();
        $this->postJson(self::BASE.'/administration/emails', $draft, $this->headersFor($admin))->assertStatus(422);
        $this->assertNull($this->postJson(self::BASE.'/auth/login', ['id_no' => $admin->id_no])->json('employee.department_sender'));
        $this->assertNull(PortalDepartmentMailboxes::for(new Employee(['department' => 'MIS', 'role' => 'User'])));
    }

    // The composer rebuilds its preview every time writing pauses, so it must not share the ordinary
    // email limit: 70 previews in a row all answer, where the 60/min ceiling would have blanked it.
    public function test_the_preview_is_not_throttled_at_the_ordinary_email_rate(): void
    {
        $headers = $this->authHeaders();

        for ($i = 0; $i < 70; $i++) {
            $this->postJson(self::BASE.'/administration/emails/preview', ['body' => 'Hi', 'footerId' => null], $headers)
                ->assertOk();
        }
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
