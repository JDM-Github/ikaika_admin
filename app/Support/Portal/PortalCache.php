<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\Cache;

/**
 * One cache generation shared by every portal read model.
 *
 * Portal data is interlocked -- a leave day closes a report day, a decision moves the queue and
 * the member's own list, a report edit changes Home -- and wiring each of those invalidations by
 * hand kept missing some. Every read model keys its cache on this one version and every write
 * bumps it, so after any write every list re-reads the database instead of serving what it held.
 *
 * Deliberately not shared: the activity log and inbox (a page view writes a log row, which would
 * otherwise wipe every cache on every click) and holidays (nothing in the portal writes them).
 */
final class PortalCache
{
    public const DATA_VERSION_KEY = 'portal:data:version';

    public static function bump(): void
    {
        $current = (int) Cache::get(self::DATA_VERSION_KEY, 1);
        Cache::forever(self::DATA_VERSION_KEY, $current + 1);
    }
}
