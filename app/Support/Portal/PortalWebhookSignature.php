<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;

/**
 * A webhook delivery is trusted only when its X-Hub-Signature is the HMAC-SHA256 of the raw body under
 * the shared secret -- the WebSub form Bitbucket and Jira both send, "sha256=<64 hex>". With no secret
 * set nothing is trusted, so an unconfigured server never acts on a delivery anyone could forge.
 */
final class PortalWebhookSignature
{
    public static function matches(Request $request, mixed $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature', '');
        if (! is_string($secret) || $secret === '' || preg_match('/^sha256=([0-9a-f]{64})$/', $header, $match) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $match[1]);
    }
}
