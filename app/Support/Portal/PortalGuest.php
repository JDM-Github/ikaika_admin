<?php

namespace App\Support\Portal;

/**
 * A guest resolved from a shared-page token: which share, and the page rules the developer granted,
 * each with the reach it was handed out at (null for a rule that carries none). It stands in for the
 * employee on a guest request, so PortalRules answers from this list instead of a role, and the
 * request is read-only (AuthenticatePortalJwt refuses every non-GET).
 */
final class PortalGuest
{
    /**
     * @param  array<string, ?string>  $rules  granted rule key => reach
     */
    public function __construct(
        public readonly int $shareId,
        public readonly array $rules,
    ) {}

    public function holds(string $key): bool
    {
        return array_key_exists($key, $this->rules);
    }
}
