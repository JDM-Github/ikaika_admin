<?php

namespace Tests\Unit;

use App\Support\Portal\PortalJiraIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Issues & Requests in Jira (2026-10-19, user): what a report becomes there, and what Jira's answer means back.
class PortalJiraIssueTest extends TestCase
{
    public function test_an_issue_says_what_the_report_says_and_names_no_one(): void
    {
        $row = (object) [
            'id' => 42,
            'kind' => 'feature',
            'title' => "  Export   the\nmap ",
            'description' => "A PDF of the team map.\nFor the weekly meeting.\n\nLandscape, please.",
            'page' => 'Administration / Team Map',
            'reported_on' => '2026-10-19 00:00:00',
            'note' => 'Ask Jamie about this',
            'assignee_id' => 7,
        ];

        $fields = PortalJiraIssue::fields($row, 'IKP', '10002', 'ikaika-portal');

        $this->assertSame(['key' => 'IKP'], $fields['project']);
        $this->assertSame(['id' => '10002'], $fields['issuetype']);
        // Named as the KAN board's cards are (2026-10-20, user).
        $this->assertSame('IP-Export-the-Map', $fields['summary']);
        // The portal's label alone (2026-10-20, user): the work type says the kind, the description the report.
        $this->assertSame(['ikaika-portal'], $fields['labels']);
        $this->assertTrue(PortalJiraIssue::isForReport($fields, 42));
        $this->assertFalse(PortalJiraIssue::isForReport($fields, 4));
        $this->assertFalse(PortalJiraIssue::isForReport([], 42));
        $this->assertSame([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'A PDF of the team map.'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'For the weekly meeting.'],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Landscape, please.']]],
            ['type' => 'rule'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kind: Feature request']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Page: Administration / Team Map']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Reported on: 2026-10-19']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sent anonymously from the IKAIKA portal (report #42).']]],
        ], $fields['description']['content']);
        $this->assertStringNotContainsString('Jamie', (string) json_encode($fields));

        // A sender who chose to be named is, and the report's mark still closes the line (2026-10-20, user).
        $named = PortalJiraIssue::fields($row, 'IKP', '10002', 'ikaika-portal', 'Jolas Mendez');
        $this->assertSame(
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sent from the IKAIKA portal by Jolas Mendez (report #42).']]],
            array_slice($named['description']['content'], -1)[0],
        );
        $this->assertTrue(PortalJiraIssue::isForReport($named, 42));
    }

    // Triage (2026-10-21, user): why Jira cancelled an issue, and the issue a duplicate's links name.
    public function test_a_cancelled_issue_says_whether_it_is_a_duplicate_and_of_which(): void
    {
        $cancelled = ['name' => 'Cancelled', 'statusCategory' => ['key' => 'done']];
        $this->assertSame('duplicate', PortalJiraIssue::cancelReason(['status' => $cancelled, 'resolution' => ['name' => 'Duplicate']], 'duplicate'));
        $this->assertSame('duplicate', PortalJiraIssue::cancelReason(['status' => $cancelled, 'labels' => ['ikaika-portal', 'Duplicate']], 'duplicate'));
        $this->assertSame('duplicate', PortalJiraIssue::cancelReason(['status' => ['name' => 'Duplicate']], 'duplicate'));
        $this->assertSame('wont_fix', PortalJiraIssue::cancelReason(['status' => $cancelled, 'resolution' => ['name' => "Won't Do"], 'labels' => ['ikaika-portal']], 'duplicate'));

        $fields = ['issuelinks' => [
            ['id' => '30002', 'type' => ['name' => 'Blocks'], 'outwardIssue' => ['id' => '10007']],
            ['id' => '30001', 'type' => ['name' => 'Duplicate'], 'outwardIssue' => ['id' => '10007']],
            ['id' => '30003', 'type' => ['name' => 'duplicate'], 'inwardIssue' => ['id' => '10050']],
        ]];
        // Read from the duplicate's side, the issue it duplicates is the outward end.
        $this->assertSame('10007', PortalJiraIssue::duplicatedIssueId($fields, 'Duplicate'));
        $this->assertNull(PortalJiraIssue::duplicatedIssueId(['issuelinks' => [$fields['issuelinks'][2]]], 'Duplicate'));
        // Whichever end the other issue is, a link of the type to it is the one to take away.
        $this->assertSame(['30001'], PortalJiraIssue::duplicateLinkIds($fields, 'Duplicate', '10007'));
        $this->assertSame(['30003'], PortalJiraIssue::duplicateLinkIds($fields, 'Duplicate', '10050'));
        $this->assertSame([], PortalJiraIssue::duplicateLinkIds([], 'Duplicate', '10007'));
    }

    // One project for production and staging (2026-10-21, user): staging labels its issues and names itself in the mark.
    public function test_another_server_than_production_labels_its_issues_and_names_itself_in_the_mark(): void
    {
        $row = (object) ['id' => 42, 'kind' => 'bug', 'title' => 'Save does nothing', 'description' => 'It broke.', 'page' => null, 'reported_on' => '2026-10-21'];

        $staging = PortalJiraIssue::fields($row, 'IPS', '10001', 'ikaika-portal', null, 'staging');
        $production = PortalJiraIssue::fields($row, 'IPS', '10001', 'ikaika-portal');

        $this->assertSame(['ikaika-portal', 'staging'], $staging['labels']);
        $this->assertSame(
            'Sent anonymously from the IKAIKA portal (staging report #42).',
            array_slice($staging['description']['content'], -1)[0]['content'][0]['text'],
        );
        // Each server takes only its own issue for the same report number.
        $this->assertTrue(PortalJiraIssue::isForReport($staging, 42, 'staging'));
        $this->assertFalse(PortalJiraIssue::isForReport($staging, 42));
        $this->assertTrue(PortalJiraIssue::isForReport($production, 42));
        $this->assertFalse(PortalJiraIssue::isForReport($production, 42, 'staging'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function summaries(): array
    {
        return [
            'as typed, small words kept small' => ['scripting and realtime clash detection', 'IP-Scripting-and-Realtime-Clash-Detection'],
            'capitals kept' => ['CLASH MANAGE V2', 'IP-CLASH-MANAGE-V2'],
            'a small word leading' => ['the map is blank', 'IP-The-Map-Is-Blank'],
            'punctuation dropped, versions kept' => ["Save doesn't work on 1.4.2!", 'IP-Save-Doesnt-Work-on-1.4.2'],
            'slashes and dashes are breaks' => ['Finance / Transactions - export', 'IP-Finance-Transactions-Export'],
            'its own prefix not doubled' => ['IP-Level viewpoint', 'IP-Level-Viewpoint'],
            'letters beyond ASCII' => ['año nuevo', 'IP-Año-Nuevo'],
            'nothing left' => ['?!', 'IP-Untitled'],
        ];
    }

    #[DataProvider('summaries')]
    public function test_a_summary_is_named_as_the_kan_boards_cards_are(string $title, string $expected): void
    {
        $this->assertSame($expected, PortalJiraIssue::summary($title));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('statuses')]
    public function test_jiras_status_becomes_the_portals(array $fields, string $expected): void
    {
        $this->assertSame($expected, PortalJiraIssue::portalStatus($fields, 'in_progress'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function statuses(): array
    {
        $done = fn (?string $resolution): array => [
            'status' => ['name' => 'Done', 'statusCategory' => ['key' => 'done']],
            'resolution' => $resolution === null ? null : ['name' => $resolution],
        ];

        return [
            'to do' => [['status' => ['statusCategory' => ['key' => 'new']]], 'new'],
            'in progress, unnamed' => [['status' => ['statusCategory' => ['key' => 'indeterminate']]], 'in_progress'],
            'done, unresolved' => [$done(null), 'fixed'],
            'done' => [$done('Done'), 'fixed'],
            "won't do" => [$done("Won't Do"), 'wont_fix'],
            "won't fix, curly" => [$done("Won\u{2019}t Fix"), 'wont_fix'],
            'declined' => [$done('Declined'), 'wont_fix'],
            'duplicate' => [$done('Duplicate'), 'wont_fix'],
            'cannot reproduce' => [$done('Cannot Reproduce'), 'wont_fix'],
            'no category' => [['status' => ['name' => 'Odd']], 'in_progress'],
            // IPS's own board (2026-10-19, user): the column's name wins over the category its owner left it in.
            'done, filed in progress' => [['status' => ['name' => 'Done', 'statusCategory' => ['key' => 'indeterminate']]], 'fixed'],
            'cancelled, filed done' => [['status' => ['name' => 'Cancelled', 'statusCategory' => ['key' => 'done']]], 'wont_fix'],
            'in review, by name' => [['status' => ['name' => 'In Review', 'statusCategory' => ['key' => 'indeterminate']]], 'in_review'],
            'testing on staging' => [['status' => ['name' => 'On Staging', 'statusCategory' => ['key' => 'done']]], 'in_review'],
            'in progress, by name' => [['status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'done']]], 'in_progress'],
            'to do, by name' => [['status' => ['name' => ' to do ', 'statusCategory' => ['key' => 'indeterminate']]], 'new'],
            'a name not listed keeps its category' => [['status' => ['name' => 'Waiting on QA', 'statusCategory' => ['key' => 'done']]], 'fixed'],
        ];
    }

    public function test_fixed_in_is_the_highest_version_named_like_one(): void
    {
        $fields = ['fixVersions' => [['name' => '1.4.2'], ['name' => 'v1.10.0'], ['name' => '2.0'], ['name' => 'Sprint 3']]];

        $this->assertSame('1.10.0', PortalJiraIssue::fixedIn($fields));
        $this->assertNull(PortalJiraIssue::fixedIn(['fixVersions' => [['name' => 'Next']]]));
        $this->assertNull(PortalJiraIssue::fixedIn([]));
    }

    public function test_a_screenshot_is_named_by_its_place_and_type_only(): void
    {
        $this->assertSame('screenshot-2.jpg', PortalJiraIssue::attachmentName(2, 'image/jpeg'));
        $this->assertSame('screenshot-1.webp', PortalJiraIssue::attachmentName(1, 'IMAGE/WEBP'));
        $this->assertSame('screenshot-3.png', PortalJiraIssue::attachmentName(3, 'application/octet-stream'));
    }
}
