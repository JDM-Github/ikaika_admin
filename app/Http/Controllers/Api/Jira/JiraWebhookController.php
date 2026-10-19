<?php

namespace App\Http\Controllers\Api\Jira;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalBugReportsJira;
use App\Support\Portal\PortalJiraWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JiraWebhookController extends Controller
{
    public function __construct(
        private readonly PortalJiraWebhook $webhook,
        private readonly PortalBugReportsJira $jira,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->webhook->isSigned($request)) {
            return response()->json(['message' => 'The signature does not match.'], 401);
        }
        $issueId = $this->webhook->linkedIssue($request);
        if ($issueId === null) {
            return response()->json(['status' => 'ignored'], 202);
        }

        // Jira waits only seconds for an answer, so the issue is read once it has one.
        defer(fn () => $this->jira->syncIssue($issueId));

        return response()->json(['status' => 'accepted'], 202);
    }
}
