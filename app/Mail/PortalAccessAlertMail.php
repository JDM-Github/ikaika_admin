<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells administrators that a signed-in user probed a route they cannot use.
 */
class PortalAccessAlertMail extends Mailable
{
    public function __construct(
        public readonly int|string $employeeId,
        public readonly string $idNo,
        public readonly string $name,
        public readonly string $role,
        public readonly string $method,
        public readonly string $path,
        public readonly string $ip,
        public readonly string $reason,
        public readonly string $occurredAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Blocked portal access attempt',
        );
    }

    public function content(): Content
    {
        $body = implode("\n", [
            'An authenticated portal user tried to reach something they cannot access.',
            '',
            'Employee id: '.$this->employeeId,
            'Id no: '.$this->idNo,
            'Name: '.$this->name,
            'Role: '.$this->role,
            'Request: '.$this->method.' '.$this->path,
            'IP: '.$this->ip,
            'When: '.$this->occurredAt,
            'Reason: '.$this->reason,
        ]);

        return new Content(
            htmlString: '<pre style="font-family:sans-serif;white-space:pre-wrap">'
                .e($body)
                .'</pre>',
        );
    }
}
