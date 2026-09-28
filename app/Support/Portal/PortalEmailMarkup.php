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
 * goes in just before its </body>.
 */
final class PortalEmailMarkup
{
    public const FORMAT_MARKUP = 'markup';

    public const FORMAT_HTML = 'html';

    private const MAX_MARKUP = 20000;

    // A hand-written HTML layout runs long; TEXT holds 65535 bytes.
    private const MAX_HTML = 60000;

    private const TEXT_COLOR = '#111827';

    private const MUTED_COLOR = '#4b5563';

    private const RULE_COLOR = '#d1d5db';

    private const LINK_COLOR = '#2563eb';

    /**
     * @var array<string, string>
     */
    private const TAG_STYLES = [
        'p' => 'margin:0 0 12px',
        'h1' => 'margin:0 0 12px;font-size:22px;line-height:1.3',
        'h2' => 'margin:0 0 12px;font-size:19px;line-height:1.35',
        'h3' => 'margin:0 0 10px;font-size:16px;line-height:1.4',
        'ul' => 'margin:0 0 12px;padding-left:22px',
        'ol' => 'margin:0 0 12px;padding-left:22px',
        'li' => 'margin:0 0 4px',
        'blockquote' => 'margin:0 0 12px;padding:2px 0 2px 12px;border-left:3px solid '.self::RULE_COLOR.';color:'.self::MUTED_COLOR,
        'code' => 'font-family:monospace;background:#f3f4f6;padding:1px 4px;border-radius:4px',
        'hr' => 'border:none;border-top:1px solid '.self::RULE_COLOR.';margin:16px 0',
    ];

    public static function format(mixed $value): ?string
    {
        $format = strtolower(trim((string) $value));

        return in_array($format, [self::FORMAT_MARKUP, self::FORMAT_HTML], true) ? $format : null;
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
     * The whole email body: the message, then the footer under a rule when one was chosen.
     */
    public static function document(
        string $body,
        ?string $footer,
        string $bodyFormat = self::FORMAT_MARKUP,
        string $footerFormat = self::FORMAT_MARKUP,
    ): string {
        $footerHtml = $footer === null || trim($footer) === ''
            ? ''
            : '<hr style="border:none;border-top:1px solid '.self::RULE_COLOR.';margin:24px 0 12px">'
                .'<div style="color:'.self::MUTED_COLOR.';font-size:13px">'.self::fragment($footer, $footerFormat).'</div>';

        if ($bodyFormat === self::FORMAT_HTML && stripos($body, '<html') !== false) {
            $close = strripos($body, '</body>');

            return $close === false
                ? $body.$footerHtml
                : substr($body, 0, $close).$footerHtml.substr($body, $close);
        }

        return '<div style="font-family:sans-serif;font-size:15px;line-height:1.6;color:'.self::TEXT_COLOR.'">'
            .self::fragment($body, $bodyFormat)
            .$footerHtml
            .'</div>';
    }

    private static function fragment(string $text, string $format): string
    {
        return $format === self::FORMAT_HTML ? $text : self::html($text);
    }

    public static function html(string $markup): string
    {
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

        // Links open in a new tab and read as links in every client, dark or light.
        return (string) preg_replace(
            '/<a href="([^"]*)"/',
            '<a href="$1" target="_blank" rel="noopener" style="color:'.self::LINK_COLOR.';text-decoration:underline"',
            $html,
        );
    }
}
