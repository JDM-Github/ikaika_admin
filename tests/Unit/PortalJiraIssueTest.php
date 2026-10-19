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
        $this->assertSame('Export the map', $fields['summary']);
        $this->assertSame(['ikaika-portal', 'ikaika-portal-42', 'portal-feature'], $fields['labels']);
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
            'in review' => [['status' => ['statusCategory' => ['key' => 'indeterminate']]], 'in_progress'],
            'done, unresolved' => [$done(null), 'fixed'],
            'done' => [$done('Done'), 'fixed'],
            "won't do" => [$done("Won't Do"), 'wont_fix'],
            "won't fix, curly" => [$done("Won\u{2019}t Fix"), 'wont_fix'],
            'declined' => [$done('Declined'), 'wont_fix'],
            'duplicate' => [$done('Duplicate'), 'wont_fix'],
            'cannot reproduce' => [$done('Cannot Reproduce'), 'wont_fix'],
            'no category' => [['status' => ['name' => 'Odd']], 'in_progress'],
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
