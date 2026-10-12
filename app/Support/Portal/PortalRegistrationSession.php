<?php

namespace App\Support\Portal;

/**
 * A candidate on their registration link, resolved from the session token: which entry, and the
 * bound address the link belongs to. It stands in for the employee on a session request -- the
 * session routes are the only ones its middleware guards -- so the entry id always comes from the
 * token and never from the request.
 */
final class PortalRegistrationSession
{
    public function __construct(
        public readonly int $registrationId,
        public readonly string $email,
        public readonly string $candidateName,
    ) {}
}
