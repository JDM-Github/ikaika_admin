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
 * it, from IKAIKA Portal. Shared pages, registrations and email sign-in send through here, so every
 * system letter looks the same as the notification emails.
 *
 * A letter goes to the mail queue, since each one is a round trip to the mail relay, seconds long.
 * A one-time code cannot wait for the queue's next minute while someone stares at the screen, so
 * codes go out straight after the response instead. Either way a failed send is logged, since the
 * request it belongs to has already answered.
 */
final class PortalLetters
{
    private const FAILED = 'portal.letter.failed';

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages  data is base64
     */
    public static function send(string $to, string $subject, string $body, array $inlineImages = []): void
    {
        try {
            Queue::push(new SendPortalMail([$to], self::letter($subject, $body, $inlineImages), self::FAILED, ['subject' => $subject]));
        } catch (Throwable $error) {
            Log::warning(self::FAILED, ['subject' => $subject, 'reason' => $error->getMessage()]);
        }
    }

    // A one-time code: sent once the answer is out, never queued behind other mail.
    public static function sendAfterResponse(string $to, string $subject, string $body): void
    {
        $mail = self::letter($subject, $body);

        defer(static function () use ($to, $mail, $subject): void {
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $error) {
                Log::warning(self::FAILED, ['subject' => $subject, 'reason' => $error->getMessage()]);
            }
        });
    }

    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages
     */
    private static function letter(string $subject, string $body, array $inlineImages = []): PortalEmailMail
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
        );
    }
}
