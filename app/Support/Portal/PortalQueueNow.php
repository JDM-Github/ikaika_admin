<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Sends queued email as soon as the request has answered (2026-10-13, user): the response leaves first
 * (LiteSpeed's lsphp finishes the request before Laravel's terminate), then this same process works the
 * queue until it is empty. The every-minute scheduler stays as the safety net for retries and anything
 * this misses. Registered once per request, however many emails it queued.
 */
final class PortalQueueNow
{
    private static bool $registered = false;

    public static function afterResponse(): void
    {
        if (self::$registered || app()->runningInConsole()) {
            return;
        }
        self::$registered = true;
        app()->terminating(static function (): void {
            self::$registered = false;
            try {
                Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]);
            } catch (Throwable) {
                // The scheduler picks the jobs up within a minute.
            }
        });
    }
}
