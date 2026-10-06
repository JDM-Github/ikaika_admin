<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bitbucket's push webhook (step 7 of the View Projects dashboard plan): a delivery is trusted only when
 * its X-Hub-Signature is the HMAC-SHA256 of the raw body under BITBUCKET_WEBHOOK_SECRET, and with no
 * secret set nothing is trusted. Only a push to a repository some project links starts a sync.
 */
final class PortalBitbucketWebhook
{
    public const EVENT = 'repo:push';

    public function isSigned(Request $request): bool
    {
        $secret = config('services.bitbucket.webhook_secret');
        $header = (string) $request->header('X-Hub-Signature', '');
        if (! is_string($secret) || $secret === '' || preg_match('/^sha256=([0-9a-f]{64})$/', $header, $match) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $match[1]);
    }

    // The linked repository a push is for, or null for any other event or a repository no project links.
    public function pushedRepository(Request $request): ?object
    {
        $fullName = $request->json('repository.full_name');
        if ($request->header('X-Event-Key') !== self::EVENT || ! is_string($fullName) || substr_count($fullName, '/') !== 1) {
            return null;
        }
        [$workspace, $slug] = explode('/', strtolower(trim($fullName)), 2);

        return DB::connection('portal')->table('bitbucket_repositories')
            ->where('workspace', $workspace)
            ->where('repo_slug', $slug)
            ->whereExists(fn ($linked) => $linked->from('projects_bitbucket_repositories')->whereColumn('repository_id', 'bitbucket_repositories.id'))
            ->first();
    }
}
