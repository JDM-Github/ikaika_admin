<?php

namespace Tests\Feature;

use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalLetters;
use App\Support\Portal\PortalNotificationEmails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every email the system writes by itself is the same letter: the letter design, the Company footer,
 * from IKAIKA Portal, opening with the reader's first name and closing with the portal's sign-off.
 * Links read by colour alone, never underlined, and nothing a mail client finds on its own (the
 * domain, the office address) is left bare for it to underline.
 */
class PortalEmailLettersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    // A greeting, then the message; no sign-off, since the footer says who it is from.
    public function test_every_letter_opens_the_same_way_and_has_no_sign_off(): void
    {
        $this->assertSame("Hi Dave,\n\nYour code is 1.", PortalLetters::body(' Dave ', ' Your code is 1. '));
        $this->assertStringStartsWith('Hi there,', PortalLetters::body(null, 'x'));
        $this->assertStringStartsWith('Hi there,', PortalLetters::body('  ', 'x'));
    }

    public function test_the_letter_design_reads_at_14px_with_links_that_are_never_underlined(): void
    {
        $html = PortalEmailMarkup::document('Hi, see [the queue](https://example.test/q).', '<p>Footer</p>', PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_HTML);

        $this->assertStringContainsString('font-size:14px;line-height:1.55', $html);
        $this->assertStringNotContainsString('text-decoration:underline', $html);
        $this->assertStringContainsString('<a href="https://example.test/q" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none">the queue</a>', $html);
        // The letterhead names the company and the portal; no bare domain is left for a client to underline.
        $this->assertStringContainsString('>IKAIKA BIM</div>', $html);
        $this->assertStringContainsString('>IKAIKA PORTAL</div>', $html);
        $this->assertStringNotContainsString('>ikaikabim.com<', $html);
        $this->assertStringContainsString('<meta name="format-detection" content="telephone=no,date=no,address=no,email=no,url=no">', $html);
        $this->assertStringContainsString('a[x-apple-data-detectors]', $html);
        // A formatted email reads at the same size as a letter.
        $this->assertStringContainsString('font-size:14px', PortalEmailMarkup::document('Plain', null));
    }

    public function test_a_letter_opens_with_its_category_and_its_subject_as_the_title(): void
    {
        $html = PortalEmailMarkup::document('Hi.', null, PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_MARKUP, 'security', 'Your <code>');

        $this->assertStringContainsString('text-transform:uppercase;">Security</td>', $html);
        $this->assertStringContainsString('>Your &lt;code&gt;</h1>', $html);
        // The category leads the subject too, so the inbox says what it is before it is opened.
        $this->assertSame('[Security] Your code', PortalEmailMarkup::subject('security', 'Your code'));
        $this->assertSame('[Request] New leave request', PortalEmailMarkup::subject('request', 'New leave request'));
        // Other, or no category, has neither pill nor prefix; a formatted email has no header at all.
        $this->assertSame('Your code', PortalEmailMarkup::subject('other', 'Your code'));
        $this->assertSame('Your code', PortalEmailMarkup::subject(null, 'Your code'));
        $this->assertStringNotContainsString('text-transform:uppercase', PortalEmailMarkup::document('Hi.', null, PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_MARKUP, 'other', 'Title'));
        $this->assertStringNotContainsString('<h1', PortalEmailMarkup::document('Hi.', null, PortalEmailMarkup::FORMAT_MARKUP, PortalEmailMarkup::FORMAT_MARKUP, 'security', 'Title'));
    }

    // A code reads off a box and a link is a button, never a bare address to copy.
    public function test_code_and_button_lines_become_blocks_of_their_own(): void
    {
        $html = PortalEmailMarkup::html("Use this code:\n::code 655991\n::button[Open the queue](https://example.test/q?a=1&b=2)\nThanks.");

        $this->assertStringNotContainsString('::code', $html);
        $this->assertStringNotContainsString('::button', $html);
        $this->assertStringContainsString('letter-spacing:7px;font-weight:700;color:#174d87;">655991</td>', $html);
        $this->assertStringContainsString('<a href="https://example.test/q?a=1&amp;b=2" target="_blank" rel="noopener" style="display:inline-block;', $html);
        $this->assertStringContainsString('>Open the queue</a>', $html);
        // A block never sits inside a paragraph, and the text around it stays its own.
        $this->assertStringNotContainsString('<p style="margin:0 0 10px"><table', $html);
        $this->assertStringContainsString('<p style="margin:0 0 10px">Thanks.</p>', $html);

        // A long password still fits a phone; a value is text, never markup.
        $this->assertStringContainsString('letter-spacing:2px;font-weight:700;color:#174d87;">Kp7mQ2xR&lt;b&gt;9Ls</td>', PortalEmailMarkup::html('::code Kp7mQ2xR<b>9Ls'));
        // A button to anything but a web address is not a button.
        $this->assertStringNotContainsString('display:inline-block', PortalEmailMarkup::html('::button[Run](javascript:alert)'));
    }

    // The approved design: navy rule, letterhead with its stripes, compact message, footer close under it, navy bar.
    public function test_the_letter_is_the_approved_design_and_compact(): void
    {
        $html = PortalEmailMarkup::document('Hi.', '<p>Footer</p>', PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_HTML);

        $this->assertStringContainsString('max-width:720px', $html);
        // The card clips what it holds, so the navy rule and bar follow its rounded corners everywhere.
        $this->assertStringContainsString('border-radius:12px;overflow:hidden;', $html);
        $this->assertStringContainsString('<!--[if mso]><table role="presentation" width="720"', $html);
        // The logo on its white tile, with room around it.
        $this->assertStringContainsString('/c_limit,w_118/c_lpad,w_152,h_158,b_white/r_24/f_png/', $html);
        $this->assertStringContainsString('/email-design/letter-header-accent.png" alt="" width="281" height="122"', $html);
        $this->assertStringContainsString('/email-design/letter-bottom-accent.png" alt="" width="128" height="14"', $html);
        $this->assertStringContainsString('padding:24px 36px 2px;', $html);
        $this->assertStringContainsString('margin:12px 0">', $html);
        // A phone drops the stripes and stacks the footer.
        $this->assertStringContainsString('@media only screen and (max-width:760px)', $html);
        $this->assertStringContainsString('.pl-accent{display:none !important;}', $html);
    }

    // The company writes as itself: no IKAIKA PORTAL under its name; the portal's letters keep it.
    public function test_a_letter_from_the_company_drops_the_portal_line(): void
    {
        $company = PortalEmailMarkup::document('Hi.', null, PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_MARKUP, 'notice', 'T', true);
        $portal = PortalEmailMarkup::document('Hi.', null, PortalEmailMarkup::FORMAT_LETTER, PortalEmailMarkup::FORMAT_MARKUP, 'notice', 'T');

        $this->assertStringContainsString('>IKAIKA BIM</div>', $company);
        $this->assertStringNotContainsString('IKAIKA PORTAL', $company);
        $this->assertStringContainsString('>IKAIKA PORTAL</div>', $portal);
        $this->assertSame('[Reminder] Due Friday', PortalEmailMarkup::subject('reminder', 'Due Friday'));
    }

    public function test_people_text_set_in_a_letter_stays_text(): void
    {
        $escaped = PortalEmailMarkup::escape('Ana_Cruz *bold* [click](javascript:alert) # 1. <b> Mary-Ann');
        $html = PortalEmailMarkup::html($escaped);

        $this->assertStringContainsString('Ana_Cruz *bold* [click](javascript:alert) # 1. &lt;b&gt; Mary-Ann', $html);
        $this->assertStringNotContainsString('<em>', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<h1', $html);

        // A web address in someone's text stays one whole link.
        $this->assertStringContainsString('<a href="https://example.test/a-b.c"', PortalEmailMarkup::html(PortalEmailMarkup::escape('see https://example.test/a-b.c today')));
    }

    public function test_the_mail_test_is_the_same_letter(): void
    {
        $mail = PortalEmailMessages::testMail();
        [$footerBody] = PortalNotificationEmails::companyFooter();

        $this->assertSame(PortalEmailMarkup::FORMAT_LETTER, $mail->bodyFormat);
        $this->assertSame(PortalEmailMarkup::CATEGORY_NOTICE, $mail->category);
        $this->assertSame($footerBody, $mail->footerBody);
        $this->assertSame(PortalNotificationEmails::FROM_NAME, $mail->fromName);
        $this->assertStringStartsWith('Hi there,', $mail->body);
        $this->assertStringNotContainsString('Best Regards', $mail->body);
    }

    public function test_a_filed_request_reads_with_for_and_the_right_article(): void
    {
        $this->assertSame('Ana filed a leave request for Monday, October 12, 2026.', PortalActivityCopy::notifyFiled('Ana', 'leave request', '2026-10-12', '2026-10-12'));
        $this->assertSame(
            'Ana filed an overtime request from Monday, October 12, 2026 to Tuesday, October 13, 2026.',
            PortalActivityCopy::notifyFiled('Ana', 'overtime request', '2026-10-12', '2026-10-13'),
        );
        $this->assertSame('Ana cancelled an offset request for Monday, October 12, 2026.', PortalActivityCopy::notifyCancelled('Ana', 'offset request', '2026-10-12'));
    }

    // The address used to be bare text, which mail clients underlined in blue as an address they found.
    public function test_the_footer_migration_links_the_address_in_an_unedited_footer_only(): void
    {
        $migration = require database_path('migrations/2026_10_13_090000_link_company_footer_address.php');
        // The footer as it stood when this migration ran; the redesign after it keeps that version as its OLD.
        $footer = (require database_path('migrations/2026_10_13_120000_redesign_company_footer.php'))::OLD;
        $this->assertStringContainsString($migration::NEW, str_replace("\r\n", "\n", $footer));

        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $old = str_replace($migration::NEW, $migration::OLD, str_replace("\r\n", "\n", $footer));
        $stale = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old footer', 'body' => str_replace("\n", "\r\n", $old), 'format' => 'html', 'created_by' => $creator]);
        $rewritten = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Own footer', 'body' => '<p>Our own address</p>', 'format' => 'html', 'created_by' => $creator]);

        $migration->up();

        $this->assertStringContainsString($migration::NEW, (string) $portal->table('email_blocks')->where('id', $stale)->value('body'));
        $this->assertSame('<p>Our own address</p>', $portal->table('email_blocks')->where('id', $rewritten)->value('body'));

        $migration->down();
        $this->assertStringContainsString($migration::OLD, (string) $portal->table('email_blocks')->where('id', $stale)->value('body'));
    }
}
