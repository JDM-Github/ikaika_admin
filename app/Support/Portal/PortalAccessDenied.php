<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;

/**
 * Abort a privilege probe. Emails admins when the actor is signed in; 401s stay quiet.
 */
final class PortalAccessDenied
{
    public const WARNING_NOTIFIED = 'This attempt was logged. Administrators have been notified.';

    public const WARNING_RECORDED = 'This attempt was logged.';

    public static function abort(Request $request, string $message): never
    {
        $notified = app(PortalAccessAlert::class)->report($request, $message);

        throw new PortalForbiddenException(
            $message,
            $notified ? self::WARNING_NOTIFIED : self::WARNING_RECORDED,
            $notified,
        );
    }
}
