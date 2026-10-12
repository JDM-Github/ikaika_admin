<?php

namespace App\Support\Portal;

use App\Mail\PortalEmailMail;
use Illuminate\Support\Facades\Mail;

/**
 * The portal's own letters to one address: the composer's letter design, the Company footer under
 * it, from IKAIKA Portal. Shared pages and email sign-in send through here, so every system letter
 * looks the same as the notification emails.
 */
final class PortalLetters
{
    /**
     * @param  list<array{name: string, data: string, mime: string}>  $inlineImages
     */
    public static function send(string $to, string $subject, string $body, array $inlineImages = []): void
    {
        [$footerBody, $footerFormat] = PortalNotificationEmails::companyFooter();
        Mail::to($to)->send(new PortalEmailMail(
            $subject,
            $body,
            $footerBody,
            PortalEmailMarkup::FORMAT_LETTER,
            $footerFormat,
            PortalNotificationEmails::FROM_NAME,
            null,
            [],
            $inlineImages,
        ));
    }
}
