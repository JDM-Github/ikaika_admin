<?php

namespace App\Support\Portal;

/**
 * What an issue or request becomes in Jira, and what Jira's answer means back in the portal
 * (2026-10-19). Pure: no request, no database, so each rule is tested on its own.
 *
 * The issue says only what the report says -- its title, what happened, its kind, the page and the day
 * -- and that it came anonymously from the portal. Never a person: not the sender, who is unknown, and
 * not MIS's note or who handles it, which stay in the portal.
 *
 * Back from Jira: To Do is New, anything in progress is In progress, and Done is Fixed -- or Won't fix
 * when it was resolved as not to be done. The fix version is the highest X.Y.Z among the issue's fix
 * versions, "v" or not.
 */
final class PortalJiraIssue
{
    // Labels hold no spaces; a resolution is matched lowercased, with a curly apostrophe made straight.
    private const NOT_DONE = ["won't do", "won't fix", 'declined', 'duplicate', 'cannot reproduce', "can't reproduce"];

    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * The fields of a new issue for a report row.
     *
     * @return array<string, mixed>
     */
    public static function fields(object $row, string $projectKey, string $issueTypeId, string $label): array
    {
        $kind = (string) ($row->kind ?? PortalBugReports::KIND_BUG);
        $kindLabel = PortalBugReports::KINDS[$kind]['label'] ?? PortalBugReports::KINDS[PortalBugReports::KIND_BUG]['label'];

        return [
            'project' => ['key' => $projectKey],
            'issuetype' => ['id' => $issueTypeId],
            'summary' => self::oneLine((string) $row->title),
            'labels' => [$label, self::reportLabel($label, (int) $row->id), 'portal-'.$kind],
            'description' => self::description((string) $row->description, [
                'Kind: '.$kindLabel,
                ...($row->page === null || trim((string) $row->page) === '' ? [] : ['Page: '.trim((string) $row->page)]),
                'Reported on: '.substr((string) $row->reported_on, 0, 10),
                'Sent anonymously from the IKAIKA portal (report #'.$row->id.').',
            ]),
        ];
    }

    // The report's own label, which finds the issue again when the answer to its create was lost.
    public static function reportLabel(string $label, int $reportId): string
    {
        return $label.'-'.$reportId;
    }

    /**
     * The portal status an issue's fields stand for, or the current one when the category says nothing.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function portalStatus(array $fields, string $current): string
    {
        $category = $fields['status']['statusCategory']['key'] ?? null;

        return match ($category) {
            'new' => PortalBugReports::STATUS_NEW,
            'indeterminate' => PortalBugReports::STATUS_IN_PROGRESS,
            'done' => self::isNotDone($fields) ? PortalBugReports::STATUS_WONT_FIX : PortalBugReports::STATUS_FIXED,
            default => $current,
        };
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

        return in_array(str_replace("\u{2019}", "'", mb_strtolower(trim($resolution))), self::NOT_DONE, true);
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
