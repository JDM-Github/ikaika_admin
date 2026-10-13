<?php

namespace App\Support\Portal;

use App\Jobs\SendPortalMail;
use App\Mail\PortalEmailMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The portal's own letters to one address: the composer's letter design, the Company footer under
 * it, from IKAIKA Portal, with its category in front of the subject and over its title. Every email
 * the system writes by itself -- codes, share letters, registrations, notifications, access alerts --
 * goes through here, and opens and closes the same way (body()), so no two of them ever look or read
 * differently. A code goes in a box (::code) and a link in a button (::button), never as bare text.
 *
 * A letter goes to the mail queue, since each one is a round trip to the mail relay, seconds long.
 * A one-time code cannot wait for the queue's next minute while someone stares at the screen, so
 * codes go out straight after the response instead. Either way a failed send is logged, since the
 * request it belongs to has already answered.
 */
final class PortalLetters
{
    private const FAILED = 'portal.letter.failed';

    // A letter with no name to greet still opens the same way.
    private const NO_NAME = 'there';

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages  data is base64
     * @param  array<string, scalar|null>  $context  what the failure log names instead of the subject; never an address
     */
    public static function send(
        string $to,
        string $category,
        string $subject,
        string $body,
        array $inlineImages = [],
        string $failureEvent = self::FAILED,
        array $context = [],
    ): void {
        $context = $context === [] ? ['subject' => $subject] : $context;
        try {
            Queue::push(new SendPortalMail([$to], self::letter($category, $subject, $body, $inlineImages), $failureEvent, $context));
        } catch (Throwable $error) {
            Log::warning($failureEvent, [...$context, 'reason' => $error->getMessage()]);
        }
    }

    // A one-time code: sent once the answer is out, never queued behind other mail.
    public static function sendAfterResponse(string $to, string $category, string $subject, string $body): void
    {
        $mail = self::letter($category, $subject, $body);

        defer(static function () use ($to, $mail, $subject): void {
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $error) {
                Log::warning(self::FAILED, ['subject' => $subject, 'reason' => $error->getMessage()]);
            }
        });
    }

    // The words every letter shares: a greeting by first name, the message, and the portal's sign-off.
    public static function body(?string $name, string $message): string
    {
        $name = trim((string) $name);

        return 'Hi '.($name !== '' ? $name : self::NO_NAME).','
            ."\n\n".trim($message)
            ."\n\nBest Regards,\n".PortalNotificationEmails::FROM_NAME;
    }

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages
     */
    public static function letter(string $category, string $subject, string $body, array $inlineImages = []): PortalEmailMail
    {
        [$footerBody, $footerFormat] = PortalNotificationEmails::companyFooter();

        return new PortalEmailMail(
            $subject,
            $body,
            $footerBody,
            PortalEmailMarkup::FORMAT_LETTER,
            $footerFormat,
            PortalNotificationEmails::FROM_NAME,
            null,
            [],
            $inlineImages,
            $category,
        );
    }
}
