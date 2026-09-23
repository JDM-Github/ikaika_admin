<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One notice from Administration / Send Email: the composed body with the footer that was
 * chosen for it. Both arrive as text they typed, escaped here rather than trusted.
 */
class PortalEmailMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly ?string $footerBody = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $footer = $this->footerBody === null
            ? ''
            : '<hr style="border:none;border-top:1px solid #d1d5db;margin:24px 0 12px">'
                .'<div style="white-space:pre-wrap;color:#4b5563;font-size:13px">'.e($this->footerBody).'</div>';

        return new Content(
            htmlString: '<div style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#111827">'
                .'<div style="white-space:pre-wrap">'.e($this->body).'</div>'
                .$footer
                .'</div>',
        );
    }
}
