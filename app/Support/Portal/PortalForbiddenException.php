<?php

namespace App\Support\Portal;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Privileged-route 403. Carries the UI warning; never includes tokens or secrets.
 */
final class PortalForbiddenException extends HttpException
{
    public function __construct(
        string $message,
        public readonly string $warning,
        public readonly bool $notified,
    ) {
        parent::__construct(403, $message);
    }
}
