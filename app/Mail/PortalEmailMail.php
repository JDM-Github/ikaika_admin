<?php

namespace App\Mail;

use App\Support\Portal\PortalEmailMarkup;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One notice from Send Email: the composed body with the footer that was chosen for it, each in its
 * own format (the toolbar's markup, or HTML sent as written). PortalEmailMarkup builds it, the same
 * builder the composer's preview shows, so the preview is the email.
 */
class PortalEmailMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly ?string $footerBody = null,
        public readonly string $bodyFormat = PortalEmailMarkup::FORMAT_MARKUP,
        public readonly string $footerFormat = PortalEmailMarkup::FORMAT_MARKUP,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: PortalEmailMarkup::document($this->body, $this->footerBody, $this->bodyFormat, $this->footerFormat));
    }
}
