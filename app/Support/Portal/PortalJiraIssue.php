<?php

namespace App\Support\Portal;

/**
 * What an issue or request becomes in Jira, and what Jira's answer means back in the portal
 * (2026-10-19). Pure: no request, no database, so each rule is tested on its own.
 *
 * The issue says only what the report says -- its title, what happened, its kind, the page and the day
 * -- and that it came from the portal: anonymously, or from the sender who chose to be named (2026-10-20,
 * user). Never MIS's note or who handles it, which stay in the portal. Its summary is named as the KAN
 * board's cards are (2026-10-20, user): IP- and the title's words joined by hyphens, each capitalised but
 * the small ones -- "save does nothing" is IP-Save-Does-Nothing. One label, the portal's own, which the admin
 * webhook filters on (2026-10-20, user): the work type already says the kind, and the report's number is
 * in the description, where a retry finds it again. A server other than production -- staging shares the
 * project since 2026-10-21 -- adds its channel as a second label and in that mark, "(staging report #N)".
 *
 * Back from Jira, by the board's column first (2026-10-19, user): To Do is New, In Progress is In progress,
 * In Review -- or a testing or staging column -- is In review (2026-10-20, user), Done is Fixed -- or Won't fix when it was resolved as not to be done -- and Cancelled is
 * Won't fix. The name leads because a team-managed project's categories are whatever its owner left them:
 * IPS files Done under "in progress" and Cancelled under "done". A name not listed falls back to the
 * category. The fix version is the highest X.Y.Z among the issue's fix versions, "v" or not.
 *
 * Won't fix is shown as Cancelled since 2026-10-21 (the value stays wont_fix), and a cancelled one says why: a
 * duplicate when Jira's resolution or status says so or the issue carries the duplicate label, else won't fix; a
 * duplicate's own links name the issue it duplicates as their outward end.
 */
final class PortalJiraIssue
{
    // Labels hold no spaces; a resolution is matched lowercased, with a curly apostrophe made straight.
    private const NOT_DONE = ["won't do", "won't fix", 'declined', 'duplicate', 'cannot reproduce', "can't reproduce"];

    // A status by its name, lowercased, a curly apostrophe made straight; 'done' still asks the resolution.
    private const BY_NAME = [
        'to do' => 'new', 'open' => 'new', 'backlog' => 'new', 'selected for development' => 'new',
        'in progress' => 'indeterminate',
        'in review' => 'review', 'review' => 'review', 'in testing' => 'review', 'testing' => 'review',
        'in qa' => 'review', 'qa' => 'review', 'on staging' => 'review', 'staging' => 'review',
        'done' => 'done', 'closed' => 'done', 'resolved' => 'done', 'fixed' => 'done',
        'cancelled' => 'not_done', 'canceled' => 'not_done', "won't do" => 'not_done', "won't fix" => 'not_done',
        'declined' => 'not_done', 'rejected' => 'not_done', 'duplicate' => 'not_done',
    ];

    private const SUMMARY_PREFIX = 'IP';

    // Words a title keeps small, as "IE-Scripting-and-Realtime-Clash-Detection" does, unless they lead it.
    private const SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'or', 'plus', 'the', 'to', 'vs', 'with'];

    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * The fields of a new issue for a report row.
     *
     * @return array<string, mixed>
     */
    public static function fields(object $row, string $projectKey, string $issueTypeId, string $label, ?string $senderName = null, ?string $environment = null): array
    {
        $kind = (string) ($row->kind ?? PortalBugReports::KIND_BUG);
        $kindLabel = PortalBugReports::KINDS[$kind]['label'] ?? PortalBugReports::KINDS[PortalBugReports::KIND_BUG]['label'];
        $mark = self::reportMark((int) $row->id, $environment);

        return [
            'project' => ['key' => $projectKey],
            'issuetype' => ['id' => $issueTypeId],
            'summary' => self::summary((string) $row->title),
            'labels' => [$label, ...($environment === null ? [] : [$environment])],
            'description' => self::description((string) $row->description, [
                'Kind: '.$kindLabel,
                ...($row->page === null || trim((string) $row->page) === '' ? [] : ['Page: '.trim((string) $row->page)]),
                'Reported on: '.substr((string) $row->reported_on, 0, 10),
                $senderName === null
                    ? 'Sent anonymously from the IKAIKA portal '.$mark.'.'
                    : 'Sent from the IKAIKA portal by '.$senderName.' '.$mark.'.',
            ]),
        ];
    }

    // The report's number as its issue's description says it, which finds the issue again when the answer to
    // its create was lost; another server's than production names itself first, so each finds only its own.
    public static function reportMark(int $reportId, ?string $environment = null): string
    {
        return '('.($environment === null ? '' : $environment.' ').'report #'.$reportId.')';
    }

    /**
     * Whether an issue's description says it is this report's. Jira's search matches words, stemmed, not the
     * exact text, so each issue it finds is read for the whole mark before it is taken.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function isForReport(array $fields, int $reportId, ?string $environment = null): bool
    {
        $description = $fields['description'] ?? null;
        if (! is_array($description)) {
            return false;
        }
        $text = '';
        array_walk_recursive($description, function ($value, $key) use (&$text): void {
            if ($key === 'text' && is_string($value)) {
                $text .= $value.' ';
            }
        });

        return str_contains($text, self::reportMark($reportId, $environment));
    }

    /**
     * The portal status an issue's fields stand for, or the current one when neither name nor category says.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function portalStatus(array $fields, string $current): string
    {
        $name = $fields['status']['name'] ?? null;
        $category = (is_string($name) ? self::BY_NAME[self::normalised($name)] ?? null : null)
            ?? $fields['status']['statusCategory']['key'] ?? null;

        return match ($category) {
            'new' => PortalBugReports::STATUS_NEW,
            'indeterminate' => PortalBugReports::STATUS_IN_PROGRESS,
            'done' => self::isNotDone($fields) ? PortalBugReports::STATUS_CANCELLED : PortalBugReports::STATUS_FIXED,
            'review' => PortalBugReports::STATUS_IN_REVIEW,
            'not_done' => PortalBugReports::STATUS_CANCELLED,
            default => $current,
        };
    }

    /**
     * Why Jira cancelled an issue on its own: a duplicate when its resolution or status says so, or when it
     * carries the duplicate label; else won't fix.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function cancelReason(array $fields, string $duplicateLabel): string
    {
        foreach ([$fields['resolution']['name'] ?? null, $fields['status']['name'] ?? null] as $name) {
            if (is_string($name) && self::normalised($name) === 'duplicate') {
                return PortalBugReports::CANCEL_DUPLICATE;
            }
        }
        foreach (is_array($fields['labels'] ?? null) ? $fields['labels'] : [] as $label) {
            if (is_string($label) && strcasecmp($label, $duplicateLabel) === 0) {
                return PortalBugReports::CANCEL_DUPLICATE;
            }
        }

        return PortalBugReports::CANCEL_WONT_FIX;
    }

    /**
     * The id of the issue a duplicate duplicates, as its own links name it: the outward end of a link of the type.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function duplicatedIssueId(array $fields, string $linkType): ?string
    {
        foreach (self::linksOfType($fields, $linkType) as $link) {
            $id = $link['outwardIssue']['id'] ?? null;
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * The links of the type between this issue and another, whichever end it is: what a duplicate pointed at
     * another original takes away.
     *
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    public static function duplicateLinkIds(array $fields, string $linkType, string $otherIssueId): array
    {
        $ids = [];
        foreach (self::linksOfType($fields, $linkType) as $link) {
            $other = $link['outwardIssue']['id'] ?? $link['inwardIssue']['id'] ?? null;
            if (is_scalar($other) && (string) $other === $otherIssueId && is_scalar($link['id'] ?? null)) {
                $ids[] = (string) $link['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private static function linksOfType(array $fields, string $linkType): array
    {
        $links = [];
        foreach (is_array($fields['issuelinks'] ?? null) ? $fields['issuelinks'] : [] as $link) {
            $type = is_array($link) ? ($link['type']['name'] ?? null) : null;
            if (is_string($type) && strcasecmp($type, $linkType) === 0) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function statusName(array $fields): ?string
    {
        $name = $fields['status']['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : null;
    }

    /**
     * The highest X.Y.Z among the fix versions, without a leading "v", or null when none is one.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fixedIn(array $fields): ?string
    {
        $highest = null;
        foreach (is_array($fields['fixVersions'] ?? null) ? $fields['fixVersions'] : [] as $version) {
            $name = is_array($version) ? trim((string) ($version['name'] ?? '')) : '';
            if (preg_match('/^v?(\d+\.\d+\.\d+)$/i', $name, $match) !== 1) {
                continue;
            }
            if ($highest === null || version_compare($match[1], $highest, '>')) {
                $highest = $match[1];
            }
        }

        return $highest;
    }

    // A screenshot's name in Jira: its place in the report and its type, nothing it was called on a phone.
    public static function attachmentName(int $position, string $mimeType): string
    {
        return 'screenshot-'.$position.'.'.(self::EXTENSIONS[strtolower($mimeType)] ?? 'png');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function isNotDone(array $fields): bool
    {
        $resolution = $fields['resolution']['name'] ?? null;
        if (! is_string($resolution)) {
            return false;
        }

        return in_array(self::normalised($resolution), self::NOT_DONE, true);
    }

    private static function normalised(string $name): string
    {
        return str_replace("\u{2019}", "'", mb_strtolower(trim($name)));
    }

    // IP-Save-Does-Nothing: the title's words, without their punctuation, each capitalised but the small ones.
    public static function summary(string $title): string
    {
        $words = [];
        foreach (preg_split('/[\s_\/-]+/u', self::oneLine($title)) ?: [] as $word) {
            $word = trim((string) preg_replace('/[^\p{L}\p{N}.]+/u', '', $word), '.');
            if ($word === '' || ($words === [] && strcasecmp($word, self::SUMMARY_PREFIX) === 0)) {
                continue;
            }
            $words[] = $words !== [] && in_array(mb_strtolower($word), self::SMALL_WORDS, true)
                ? mb_strtolower($word)
                : mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }

        return mb_substr(self::SUMMARY_PREFIX.'-'.($words === [] ? 'Untitled' : implode('-', $words)), 0, 255);
    }

    private static function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($line === '' ? 'Untitled' : $line, 0, 255);
    }

    /**
     * Atlassian Document Format: the report's paragraphs, a rule, then one line per fact.
     *
     * @param  list<string>  $facts
     * @return array<string, mixed>
     */
    private static function description(string $text, array $facts): array
    {
        $content = [];
        foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $paragraph) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $paragraph) ?: []), fn (string $line): bool => $line !== ''));
            if ($lines === []) {
                continue;
            }
            $inline = [];
            foreach ($lines as $index => $line) {
                if ($index > 0) {
                    $inline[] = ['type' => 'hardBreak'];
                }
                $inline[] = ['type' => 'text', 'text' => $line];
            }
            $content[] = ['type' => 'paragraph', 'content' => $inline];
        }
        $content[] = ['type' => 'rule'];
        foreach ($facts as $fact) {
            $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $fact]]];
        }

        return ['type' => 'doc', 'version' => 1, 'content' => $content];
    }
}
