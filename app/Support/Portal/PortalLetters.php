<?php

namespace App\Support\Portal;

use App\Mail\PortalEmailMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The portal's own letters to one address: the composer's letter design, the Company footer under
 * it, from IKAIKA Portal. Shared pages and email sign-in send through here, so every system letter
 * looks the same as the notification emails.
 *
 * Sent once the answer is out: each letter is a round trip to the mail relay, seconds apart, and a
 * sign-in that mails two codes would otherwise hold the screen for both. A failed send is logged,
 * as the notification emails do, since the request it belongs to has already answered.
 */
final class PortalLetters
{
    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages
     */
    public static function send(string $to, string $subject, string $body, array $inlineImages = []): void
    {
        [$footerBody, $footerFormat] = PortalNotificationEmails::companyFooter();
        $mail = new PortalEmailMail(
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

        defer(static function () use ($to, $mail, $subject): void {
            try {
                Mail::to($to)->send($mail);
            } catch (Throwable $error) {
                Log::warning('portal.letter.failed', ['subject' => $subject, 'reason' => $error->getMessage()]);
            }
        });
    }
}
