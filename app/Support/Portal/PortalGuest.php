<?php

namespace App\Support\Portal;

/**
 * A guest resolved from a shared-page token: which share, its label, and the page rules the developer
 * granted. It stands in for the employee on a guest request, so PortalRules answers from this list
 * instead of a role, and the request is read-only (AuthenticatePortalJwt refuses every non-GET).
 */
final class PortalGuest
{
    /**
     * @param  list<string>  $rules
     */
    public function __construct(
        public readonly int $shareId,
        public readonly string $label,
        public readonly array $rules,
    ) {}

    public function holds(string $key): bool
    {
        return in_array($key, $this->rules, true);
    }
}
