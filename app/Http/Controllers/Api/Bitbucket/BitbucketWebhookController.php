<?php

namespace App\Http\Controllers\Api\Bitbucket;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalBitbucketWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BitbucketWebhookController extends Controller
{
    // A push's sync counts only a small batch of lines: the hourly sync finishes a long history.
    private const PUSH_COUNT_LIMIT = 50;

    public function __construct(
        private readonly PortalBitbucketWebhook $webhook,
        private readonly PortalBitbucketSync $sync,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->webhook->isSigned($request)) {
            return response()->json(['message' => 'The signature does not match.'], 401);
        }
        $repository = $this->webhook->pushedRepository($request);
        if ($repository === null) {
            return response()->json(['status' => 'ignored'], 202);
        }

        // Bitbucket waits only seconds for an answer, so the sync runs once it has one.
        defer(fn () => $this->sync->sync($repository, self::PUSH_COUNT_LIMIT));

        return response()->json(['status' => 'accepted'], 202);
    }
}
