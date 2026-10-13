<?php

namespace App\Jobs;

use App\Support\Portal\PortalEmailMessages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One copy of a Send Email message: a member's own copy, the one email to typed addresses, or the
 * shared CC and BCC copy. The mail is rebuilt from the outbox row when it leaves, so the queue holds
 * addresses and ids only, and the outcome is written back onto that row as each copy lands.
 */
final class DeliverPortalEmail implements ShouldQueue
{
    use Queueable;
    use RetriesMailDelivery;

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{id: ?int, name: string}>  $recipients  who this copy counts for; none for the CC and BCC copy
     * @param  array{title: string, message: string, linkPath: ?string, linkLabel: ?string, payload: array<string, string>, actorId: int}|null  $notice  the bell notice a reached member gets
     */
    public function __construct(
        public readonly int $messageId,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly array $recipients,
        public readonly ?array $notice = null,
    ) {}

    public function handle(PortalEmailMessages $messages): void
    {
        try {
            if (! $messages->deliverCopy($this->messageId, $this->to, $this->cc, $this->bcc)) {
                return;
            }
        } catch (Throwable $error) {
            if (! $this->retryLater()) {
                $messages->settleCopy($this->messageId, $this->recipients, $error);
            }

            return;
        }
        $messages->settleCopy($this->messageId, $this->recipients, null, $this->notice);
    }

    // The worker gave up on a copy it lost, such as one killed mid-send. Its own error names a queue
    // internal, not anything the reader can act on, so the row says it plainly instead.
    public function failed(?Throwable $error): void
    {
        app(PortalEmailMessages::class)->settleCopy(
            $this->messageId,
            $this->recipients,
            new RuntimeException('The mail queue stopped while sending this copy.'),
        );
    }
}
