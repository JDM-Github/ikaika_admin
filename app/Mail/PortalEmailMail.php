<?php

namespace App\Mail;

use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalPrivateFiles;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * One notice from Send Email: the composed body with the footer that was chosen for it, each in its
 * own format (the toolbar's markup, or HTML sent as written). PortalEmailMarkup builds it, the same
 * builder the composer's preview shows, so the preview is the email.
 *
 * Sent as an administrator, it still leaves from the portal's own address -- only the display name
 * becomes "<Name> via IKAIKA Portal" and Reply-To points at them -- so it passes the domain's own
 * sender checks rather than looking like a spoofed personal address.
 *
 * Attached files come off the private disk under the names they were attached with. Inline images
 * (a share's QR) are embedded under their name, so the body shows them as cid:<name>. They are held
 * as base64 and embedded only at send time, so a queued copy stores as JSON with no closure in it.
 */
class PortalEmailMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly ?string $footerBody = null,
        public readonly string $bodyFormat = PortalEmailMarkup::FORMAT_MARKUP,
        public readonly string $footerFormat = PortalEmailMarkup::FORMAT_MARKUP,
        public readonly ?string $fromName = null,
        public readonly ?string $replyToAddress = null,
        /** @var list<array{path: string, name: string, mime: string}> */
        public readonly array $attachedFiles = [],
        /** @var list<array{name: string, data: string, mime: string}> data is base64 */
        public readonly array $inlineImages = [],
    ) {}

    // Called by the mailer just before it sends, after the queue has rebuilt this mail.
    public function build(): void
    {
        foreach ($this->inlineImages as $image) {
            $this->withSymfonyMessage(static fn (Email $message): Email => $message->embed((string) base64_decode($image['data'], true), $image['name'], $image['mime']));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromName === null ? null : new Address((string) config('mail.from.address'), $this->fromName),
            replyTo: $this->replyToAddress === null ? [] : [new Address($this->replyToAddress, $this->fromName)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: PortalEmailMarkup::document($this->body, $this->footerBody, $this->bodyFormat, $this->footerFormat));
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (array $file): Attachment => Attachment::fromStorageDisk(PortalPrivateFiles::DISK, $file['path'])
                ->as($file['name'])
                ->withMime($file['mime']),
            $this->attachedFiles,
        );
    }
}
