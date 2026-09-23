<?php

namespace Tests\Unit;

use App\Support\Portal\PortalRecycleBinPresenter;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Tests\TestCase;

class PortalRecycleBinPresenterTest extends TestCase
{
    public function test_title_prefers_the_stored_label_then_the_kind(): void
    {
        $this->assertSame('Daily Report', PortalRecycleBinPresenter::title(null, PortalSubmittedReportPresenter::KIND_DAILY));
        $this->assertSame('Late Report', PortalRecycleBinPresenter::title('', PortalSubmittedReportPresenter::KIND_LATE));
        $this->assertSame('Daily Report', PortalRecycleBinPresenter::title('Daily Report', PortalSubmittedReportPresenter::KIND_LATE));
    }

    public function test_kind_treats_anything_but_late_as_daily(): void
    {
        $this->assertSame('late', PortalRecycleBinPresenter::kind('late'));
        $this->assertSame('daily', PortalRecycleBinPresenter::kind('daily'));
        $this->assertSame('daily', PortalRecycleBinPresenter::kind(null));
    }

    public function test_type_is_derived_from_the_resource_prefix(): void
    {
        $this->assertSame('report', PortalRecycleBinPresenter::type(null));
        $this->assertSame('report', PortalRecycleBinPresenter::type(''));
        $this->assertSame('report', PortalRecycleBinPresenter::type('reports.submitted'));
        $this->assertSame('request', PortalRecycleBinPresenter::type('requests.leave'));
        $this->assertSame('project', PortalRecycleBinPresenter::type('projects.track'));
    }

    public function test_generated_report_prefers_the_stored_report_and_never_exposes_lines(): void
    {
        $item = [
            'kind' => 'daily',
            'submittedOn' => '2026-08-20',
            'employeeId' => 12,
            'employeeIdNo' => '260701-0020',
            'employeeName' => 'Jane Doe',
        ];
        $fromStored = PortalRecycleBinPresenter::generatedReport([
            'lines' => [['id' => 1, 'hours_rendered' => 8, 'remarks' => 'LINE-SECRET']],
            'report' => [
                'referenceCode' => '260701-0020',
                'memberName' => 'Jane Doe',
                'reason' => 'Late because of weather',
                'entries' => [[
                    'id' => '41',
                    'projectLabel' => '260005 IKAIKA Portal V2',
                    'activityLabel' => 'WEB APPLICATION DEVELOPMENT',
                    'earnCodeLabel' => '01 Regular Working Day',
                    'hoursRendered' => 8,
                    'elementChange' => 2,
                    'secret' => 'ENTRY-SECRET',
                ]],
            ],
        ], $item);

        $this->assertSame('Late because of weather', $fromStored['reason']);
        $this->assertSame('260005 IKAIKA Portal V2', $fromStored['entries'][0]['projectLabel'] ?? null);
        $this->assertSame(
            ['id', 'projectLabel', 'activityLabel', 'earnCodeLabel', 'hoursRendered', 'elementChange'],
            array_keys($fromStored['entries'][0] ?? []),
        );
        $this->assertArrayNotHasKey('secret', $fromStored['entries'][0] ?? []);
        $this->assertArrayNotHasKey('lines', $fromStored);
    }

    public function test_generated_report_falls_back_to_line_hours_when_report_is_missing(): void
    {
        $fromLines = PortalRecycleBinPresenter::generatedReport([
            'lines' => [[
                'id' => 9,
                'hours_rendered' => 6.5,
                'change_in_elements' => 1,
                'remarks' => 'Kept as reason',
            ]],
        ], [
            'kind' => 'late',
            'submittedOn' => '2026-08-21',
            'employeeId' => 12,
            'employeeIdNo' => '260701-0020',
            'employeeName' => 'Jane Doe',
        ]);

        $this->assertSame('Kept as reason', $fromLines['reason']);
        $this->assertSame('9', $fromLines['entries'][0]['id'] ?? null);
        $this->assertSame('Unassigned', $fromLines['entries'][0]['projectLabel'] ?? null);
        $this->assertSame(6.5, $fromLines['entries'][0]['hoursRendered'] ?? null);
    }
}
