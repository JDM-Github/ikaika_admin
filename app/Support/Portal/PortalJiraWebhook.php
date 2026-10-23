<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Jira's admin webhook for Issues & Requests (2026-10-19): a delivery is trusted only when signed with
 * JIRA_WEBHOOK_SECRET, and only an update or deletion of an issue a report became starts anything.
 *
 * The delivery is a nudge, not the news: the issue is read fresh from Jira afterwards, so a delivery
 * that comes twice, late or out of order changes nothing a fresh read would not. Production and staging share
 * one project (2026-10-21, user), so only an issue of a report sent on this server counts -- and not one archived
 * or deleted, which is out of Jira's way until it is back.
 */
final class PortalJiraWebhook
{
    public const EVENTS = ['jira:issue_updated', 'jira:issue_deleted'];

    public function __construct(private readonly PortalJiraEnvironment $environment) {}

    public function isSigned(Request $request): bool
    {
        return PortalWebhookSignature::matches($request, config('services.jira.webhook_secret'));
    }

    // The Jira id of a linked issue the delivery is about, or null for any other event or issue.
    public function linkedIssue(Request $request): ?string
    {
        $issueId = $request->json('issue.id');
        if (! in_array($request->json('webhookEvent'), self::EVENTS, true) || ! is_scalar($issueId) || ! ctype_digit((string) $issueId)) {
            return null;
        }
        $isLinked = PortalBugReportsJira::tracked($this->environment->scope(DB::connection('portal')->table('bug_reports')))
            ->where('jira_issue_id', (string) $issueId)
            ->where('jira_state', '!=', PortalBugReportsJira::STATE_DELETED)
            ->exists();

        return $isLinked ? (string) $issueId : null;
    }
}
