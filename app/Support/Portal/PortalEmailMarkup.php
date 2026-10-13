<?php

namespace App\Support\Portal;

use Illuminate\Support\Str;

/**
 * The HTML every portal email is made of, built from the light markup the composer's toolbar writes
 * (**bold**, _italic_, # headings, lists, > quotes, [links](https://...)). The send and the composer's
 * preview both come through document(), so what the preview shows is what the inbox gets.
 *
 * In markup, anything typed as raw HTML is escaped and only http, https and mailto links are kept.
 * A single line break stays a line break, so plain text written before the toolbar existed reads
 * exactly as it always did. Styles are inline because most mail clients drop <style> blocks.
 *
 * A body or footer in the html format is an administrator's own HTML and goes out as written. A
 * whole document (one with its own <html>) is the email itself: nothing wraps it, and the footer
 * goes where it marks FOOTER_SLOT, or else just before its </body>.
 *
 * The letter format is markup set in the letter design (letter()): a letterhead, then the email's
 * category as a coloured pill and its subject as the title, the message on a white sheet, the footer
 * at its foot.
 *
 * Two lines of markup become blocks of their own, in any markup body: `::code 655991` sets a one-time
 * code or a password in a box, and `::button[Open the page](https://...)` a button, so a long address
 * never has to be read or copied. The design is a whole document, so the HTML of a letter -- what
 * the preview hands back for one with no footer -- sends exactly as the letter does, and a writer can
 * change the design there.
 */
final class PortalEmailMarkup
{
    public const FORMAT_MARKUP = 'markup';

    public const FORMAT_HTML = 'html';

    public const FORMAT_LETTER = 'letter';

    // The categories the system's own emails use; the composer's come from PortalEmail::CATEGORIES.
    public const CATEGORY_NOTICE = 'notice';

    public const CATEGORY_EVENT = 'event';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_WARNING = 'warning';

    public const CATEGORY_REQUEST = 'request';

    public const CATEGORY_PROJECT = 'project';

    // Where a whole document wants the footer; kept, unseen, when there is none, so the HTML stays a design.
    public const FOOTER_SLOT = '<!-- portal:footer -->';

    private const BRAND_COLOR = '#1f4e8c';

    // The company mark the Company footer carries.
    private const LETTER_LOGO = 'https://res.cloudinary.com/ezmjw0z8/image/upload/c_limit,w_112,f_auto,q_auto/v1790630829/email-upload/global/e653c017-1c81-4427-95b5-225b7ee753e4.png';

    private const MAX_MARKUP = 20000;

    // A hand-written HTML layout runs long; TEXT holds 65535 bytes.
    private const MAX_HTML = 60000;

    private const TEXT_COLOR = '#111827';

    // Inter where the mail client loads web fonts (Apple Mail, iOS, Thunderbird); elsewhere the stack.
    public const FONT_STACK = "Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /*
     * Asks for Inter, and keeps desktop Outlook -- which drops to Times New Roman when a web font it
     * cannot load leads the stack -- on Segoe UI instead. Clients that strip <style> simply ignore it.
     */
    private const FONT_HEAD = "<style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');</style>"
        ."<!--[if mso]><style>body,table,td,div,p,a,span,li,h1,h2,h3{font-family:'Segoe UI',Arial,sans-serif !important;}</style><![endif]-->";

    private const MUTED_COLOR = '#4b5563';

    private const RULE_COLOR = '#d1d5db';

    private const LINK_COLOR = '#2563eb';

    // The message text, one size for every design, so a letter and a formatted email read alike.
    private const TEXT_SIZE = '14px';

    /*
     * Phones and webmail turn a bare address, date or domain into their own blue, underlined link. The
     * meta asks them not to; the rule styles any they make anyway like the text around it.
     */
    private const DETECTION_HEAD = '<meta name="format-detection" content="telephone=no,date=no,address=no,email=no,url=no">'
        .'<style>a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;font-size:inherit !important;font-family:inherit !important;font-weight:inherit !important;line-height:inherit !important;}</style>';

    /*
     * What an email is about, shown as a pill above its title and in front of its subject. The first
     * four are the composer's own categories; the rest are the system's. Other has no pill.
     *
     * @var array<string, array{0: string, 1: string, 2: string}> key => label, text colour, background
     */
    private const CATEGORIES = [
        self::CATEGORY_NOTICE => ['Notice', '#1f4e8c', '#e8eef7'],
        self::CATEGORY_EVENT => ['Event', '#1e7b3c', '#e7f6ec'],
        'complaint' => ['Complaint', '#b42318', '#fdecec'],
        self::CATEGORY_SECURITY => ['Security', '#4338ca', '#eef0fe'],
        self::CATEGORY_WARNING => ['Warning', '#b45309', '#fff4e5'],
        self::CATEGORY_REQUEST => ['Request', '#0f766e', '#e6f6f4'],
        self::CATEGORY_PROJECT => ['Project', '#7e22ce', '#f3e8ff'],
    ];

    // A block line: `::code <value>` or `::button[<label>](<link>)`, alone on its line.
    private const BLOCK_LINE = '/^::(code|button)[ \t]*(.+?)[ \t]*$/m';

    /**
     * @var array<string, string>
     */
    private const TAG_STYLES = [
        'p' => 'margin:0 0 12px',
        'h1' => 'margin:0 0 12px;font-size:20px;line-height:1.3',
        'h2' => 'margin:0 0 12px;font-size:17px;line-height:1.35',
        'h3' => 'margin:0 0 10px;font-size:15px;line-height:1.4',
        'ul' => 'margin:0 0 12px;padding-left:22px',
        'ol' => 'margin:0 0 12px;padding-left:22px',
        'li' => 'margin:0 0 4px',
        // A panel for details set apart from the message: who, what, when.
        'blockquote' => 'margin:4px 0 16px;padding:12px 16px 0;background-color:#f8fafc;border:1px solid #e5e7eb;border-left:3px solid '.self::BRAND_COLOR.';border-radius:8px;color:#374151',
        'code' => 'font-family:monospace;background:#f3f4f6;padding:1px 4px;border-radius:4px',
        'hr' => 'border:none;border-top:1px solid '.self::RULE_COLOR.';margin:16px 0',
    ];

    public static function format(mixed $value): ?string
    {
        $format = strtolower(trim((string) $value));

        return in_array($format, [self::FORMAT_MARKUP, self::FORMAT_HTML, self::FORMAT_LETTER], true) ? $format : null;
    }

    /**
     * The body and block limit for a format, the same for a send, a preview and the library.
     */
    public static function holdToLimit(string $body, string $format): void
    {
        if ($format === self::FORMAT_HTML) {
            if (strlen($body) > self::MAX_HTML) {
                abort(422, 'The HTML cannot exceed 60000 characters.');
            }

            return;
        }
        if (strlen($body) > self::MAX_MARKUP) {
            abort(422, 'The body cannot exceed 20000 characters.');
        }
    }

    // A caller from before the HTML switch sends no format, and is markup.
    public static function validated(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return self::FORMAT_MARKUP;
        }
        $format = self::format($raw);
        if ($format === null) {
            abort(422, 'Choose Formatted or HTML.');
        }

        return $format;
    }

    /**
     * The whole email body: the message, then the footer under a rule when one was chosen. A letter
     * also carries its category and title (the subject) above the message.
     */
    public static function document(
        string $body,
        ?string $footer,
        string $bodyFormat = self::FORMAT_MARKUP,
        string $footerFormat = self::FORMAT_MARKUP,
        ?string $category = null,
        ?string $title = null,
    ): string {
        $footerHtml = $footer === null || trim($footer) === ''
            ? ''
            : '<hr style="border:none;border-top:1px solid '.self::RULE_COLOR.';margin:16px 0 12px">'
                .'<div style="color:'.self::MUTED_COLOR.';font-size:13px">'.self::fragment($footer, $footerFormat).'</div>';

        if ($bodyFormat === self::FORMAT_LETTER) {
            $body = self::letter(self::header($category, $title).self::html($body));
            $bodyFormat = self::FORMAT_HTML;
        }

        if ($bodyFormat === self::FORMAT_HTML && stripos($body, '<html') !== false) {
            if ($footerHtml === '') {
                return $body;
            }
            $slot = strpos($body, self::FOOTER_SLOT);
            if ($slot !== false) {
                return substr_replace($body, $footerHtml, $slot, strlen(self::FOOTER_SLOT));
            }
            $close = strripos($body, '</body>');

            return $close === false
                ? $body.$footerHtml
                : substr($body, 0, $close).$footerHtml.substr($body, $close);
        }

        return self::FONT_HEAD
            .'<div style="font-family:'.self::FONT_STACK.';font-size:'.self::TEXT_SIZE.';line-height:1.6;color:'.self::TEXT_COLOR.'">'
            .self::fragment($body, $bodyFormat)
            .$footerHtml
            .'</div>';
    }

    private static function fragment(string $text, string $format): string
    {
        return $format === self::FORMAT_HTML ? $text : self::html($text);
    }

    /**
     * The letter design around a message already in HTML. Tables and inline styles, as mail clients
     * need; laid out line by line, since a writer who takes the HTML changes the design here.
     */
    public static function letter(string $content): string
    {
        $font = 'font-family:'.self::FONT_STACK;

        return implode("\n", [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width,initial-scale=1">',
            self::FONT_HEAD,
            self::DETECTION_HEAD,
            '</head>',
            '<body style="margin:0;padding:0;background-color:#f3f4f6;">',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;border-collapse:collapse;">',
            '  <tr>',
            '    <td align="center" style="padding:24px 12px;">',
            '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;border-collapse:separate;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">',
            '        <!-- Letterhead -->',
            '        <tr>',
            '          <td style="height:6px;line-height:6px;font-size:0;background-color:'.self::BRAND_COLOR.';border-radius:12px 12px 0 0;">&nbsp;</td>',
            '        </tr>',
            '        <tr>',
            '          <td style="padding:22px 32px 18px;border-bottom:1px solid #e5e7eb;">',
            '            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">',
            '              <tr>',
            '                <td width="44" valign="middle" style="width:44px;padding:0 12px 0 0;">',
            '                  <img src="'.self::LETTER_LOGO.'" alt="IKAIKA BIM" width="44" style="display:block;width:44px;height:auto;border:0;">',
            '                </td>',
            '                <td valign="middle" style="'.$font.';">',
            '                  <div style="font-size:15px;line-height:20px;font-weight:700;letter-spacing:0.3px;color:'.self::BRAND_COLOR.';">IKAIKA BIM</div>',
            '                  <div style="font-size:12px;line-height:18px;color:#6b7280;"><a href="https://ikaikabim.com" target="_blank" rel="noopener" style="color:#6b7280;text-decoration:none;">ikaikabim.com</a></div>',
            '                </td>',
            '              </tr>',
            '            </table>',
            '          </td>',
            '        </tr>',
            '        <!-- Letter -->',
            '        <tr>',
            '          <td style="padding:28px 32px 4px;'.$font.';font-size:'.self::TEXT_SIZE.';line-height:1.6;color:'.self::TEXT_COLOR.';">',
            $content,
            '          </td>',
            '        </tr>',
            '        <!-- Footer: the one picked under the message goes in at portal:footer. -->',
            '        <tr>',
            '          <td style="padding:0 32px 28px;'.$font.';">',
            '            '.self::FOOTER_SLOT,
            '          </td>',
            '        </tr>',
            '      </table>',
            '    </td>',
            '  </tr>',
            '</table>',
            '</body>',
            '</html>',
        ]);
    }

    // The subject an inbox shows: the category in front, so a reader knows what it is before opening it.
    public static function subject(?string $category, string $subject): string
    {
        $known = self::CATEGORIES[strtolower((string) $category)] ?? null;

        return $known === null ? $subject : '['.$known[0].'] '.$subject;
    }

    // The category pill and the title over a letter's message; nothing when neither is known.
    private static function header(?string $category, ?string $title): string
    {
        $known = self::CATEGORIES[strtolower((string) $category)] ?? null;
        $title = trim((string) $title);
        $html = '';
        if ($known !== null) {
            [$label, $color, $background] = $known;
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;border-collapse:separate;"><tr>'
                .'<td style="padding:3px 10px;border-radius:999px;background-color:'.$background.';color:'.$color.';font-size:11px;line-height:16px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;">'
                .e($label).'</td></tr></table>';
        }
        if ($title !== '') {
            $html .= '<h1 style="margin:0 0 18px;font-size:20px;line-height:1.3;font-weight:700;color:'.self::TEXT_COLOR.';">'.e($title).'</h1>';
        }

        return $html;
    }

    // A code or password in a box of its own: large, spaced and easy to read off or copy.
    private static function codeBlock(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // A short code reads best spaced wide; a long password must still fit a phone.
        $size = strlen($value) <= 8 ? 'font-size:30px;line-height:38px;letter-spacing:8px' : 'font-size:22px;line-height:30px;letter-spacing:3px';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 18px;border-collapse:separate;"><tr>'
            .'<td align="center" style="padding:16px 12px;background-color:#f3f6fb;border:1px solid #dbe4f0;border-radius:10px;'
            ."font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;".$size.';font-weight:700;color:'.self::BRAND_COLOR.';">'
            .e($value).'</td></tr></table>';
    }

    // A button for a link: its label is what the reader clicks, never the address itself.
    private static function buttonBlock(string $spec): ?string
    {
        if (preg_match('/^\[([^\]]+)\]\((https?:\/\/\S+|\/\S*)\)$/', trim($spec), $match) !== 1) {
            return null;
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;border-collapse:separate;"><tr>'
            .'<td style="border-radius:8px;background-color:'.self::BRAND_COLOR.';">'
            .'<a href="'.e($match[2]).'" target="_blank" rel="noopener" style="display:inline-block;padding:11px 22px;font-family:'.self::FONT_STACK.';font-size:14px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">'
            .e($match[1]).'</a></td></tr></table>';
    }

    /*
     * Text from people (names, titles, reasons) set inside a letter's markup, so a * or [ in it stays
     * itself. Dots and dashes are left alone: they only mean something at a line's start, where this
     * text never sits, and escaping them would cut a web address in it in two.
     */
    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+!|<>~])/', '\\\\$1', $text);
    }

    public static function html(string $markup): string
    {
        // Each block stands in as a paragraph of its own until Markdown is done, then takes its place.
        $blocks = [];
        $markup = (string) preg_replace_callback(self::BLOCK_LINE, static function (array $line) use (&$blocks): string {
            $html = $line[1] === 'code' ? self::codeBlock($line[2]) : self::buttonBlock($line[2]);
            if ($html === null) {
                return $line[0];
            }
            $token = 'PORTALBLOCK'.count($blocks).'END';
            $blocks[$token] = $html;

            return "\n\n".$token."\n\n";
        }, $markup);

        $html = Str::markdown($markup, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        $html = (string) preg_replace_callback(
            '/<(p|h1|h2|h3|ul|ol|li|blockquote|code|hr)(\s[^>]*)?>/',
            static fn (array $tag): string => '<'.$tag[1].($tag[2] ?? '').' style="'.self::TAG_STYLES[$tag[1]].'">',
            $html,
        );

        // CommonMark writes <img ... /> with no size; a large picture must not overflow the message.
        $html = (string) preg_replace('/<img\s/', '<img style="display:block;max-width:100%;height:auto;border:0" ', $html);

        // Links open in a new tab and read as links by their colour alone, dark or light: no underline.
        $html = (string) preg_replace(
            '/<a href="([^"]*)"/',
            '<a href="$1" target="_blank" rel="noopener" style="color:'.self::LINK_COLOR.';text-decoration:none"',
            $html,
        );

        // Last, so nothing above restyles a block's own table or button.
        $paragraph = '<p style="'.self::TAG_STYLES['p'].'">';
        foreach ($blocks as $token => $block) {
            $html = str_replace([$paragraph.$token.'</p>', $token], $block, $html);
        }

        return $html;
    }
}
