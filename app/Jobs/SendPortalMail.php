<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One system email sent off the request: notifications, access alerts and the share letters wait
 * here instead of holding the action that caused them for a relay round trip each.
 *
 * Whatever caused it has already answered, so a send that fails for good is logged under the
 * caller's own event name, never thrown at anyone.
 */
final class SendPortalMail implements ShouldQueue
{
    use Queueable;
    use RetriesMailDelivery;

    /**
     * @param  list<string>  $to
     * @param  array<string, scalar|null>  $context  what the failure log names; never an address
     */
    public function __construct(
        public readonly array $to,
        public readonly Mailable $mail,
        public readonly string $failureEvent,
        public readonly array $context = [],
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->to)->send($this->mail);
        } catch (Throwable $error) {
            if (! $this->retryLater()) {
                $this->failed($error);
            }
        }
    }

    // Also what the worker calls when it gives up on a job it lost, such as one killed mid-send.
    public function failed(?Throwable $error): void
    {
        Log::warning($this->failureEvent, [...$this->context, 'reason' => $error?->getMessage()]);
    }
}
