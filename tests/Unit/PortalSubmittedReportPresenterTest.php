<?php

namespace Tests\Unit;

use App\Support\Portal\PortalSubmittedReportPresenter;
use Tests\TestCase;

class PortalSubmittedReportPresenterTest extends TestCase
{
    public function test_empty_and_on_time_are_daily_and_anything_else_is_late(): void
    {
        $this->assertSame('daily', PortalSubmittedReportPresenter::kind(null));
        $this->assertSame('daily', PortalSubmittedReportPresenter::kind(''));
        $this->assertSame('daily', PortalSubmittedReportPresenter::kind('No'));
        $this->assertSame('daily', PortalSubmittedReportPresenter::kind('on time'));
        $this->assertSame('late', PortalSubmittedReportPresenter::kind('Yes'));
        $this->assertSame('late', PortalSubmittedReportPresenter::kind('Late'));
    }

    public function test_labels_skip_blank_parts_and_never_return_an_empty_string(): void
    {
        $this->assertSame('260005 IKAIKA Portal V2', PortalSubmittedReportPresenter::projectLabel('260005', 'IKAIKA Portal V2'));
        $this->assertSame('Coinbase', PortalSubmittedReportPresenter::projectLabel(null, 'Coinbase'));
        $this->assertSame('Unassigned', PortalSubmittedReportPresenter::projectLabel(null, null));
        $this->assertSame('5000- WEB', PortalSubmittedReportPresenter::activityLabel('5000- WEB', '5000'));
        $this->assertSame('5000', PortalSubmittedReportPresenter::activityLabel(null, '5000'));
        $this->assertSame('01 Regular Working Day', PortalSubmittedReportPresenter::earnCodeLabel('01 Regular Working Day'));
        $this->assertSame('Unassigned', PortalSubmittedReportPresenter::earnCodeLabel(''));
    }

    public function test_member_name_and_reason_trim_empty_values(): void
    {
        $this->assertSame('Jamie Pingol', PortalSubmittedReportPresenter::memberName('Jamie', 'Pingol'));
        $this->assertSame('Member', PortalSubmittedReportPresenter::memberName(' ', null));
        $this->assertSame('Late because of rain', PortalSubmittedReportPresenter::reason('Late because of rain'));
        $this->assertNull(PortalSubmittedReportPresenter::reason('  '));
        $this->assertSame('220302-0003', PortalSubmittedReportPresenter::referenceCode('220302-0003', 15));
        $this->assertSame('15', PortalSubmittedReportPresenter::referenceCode(' ', 15));
    }

    public function test_leave_offset_and_overtime_are_each_named(): void
    {
        $this->assertSame('leave', PortalSubmittedReportPresenter::occupancy(null, null, null, null));
        $this->assertSame('leave', PortalSubmittedReportPresenter::occupancy('Leave', 8, null, null));
        $this->assertSame('offset', PortalSubmittedReportPresenter::occupancy('Offset', 8, '2026-05-27', '2026-06-01'));
        $this->assertSame('offset', PortalSubmittedReportPresenter::occupancy(null, 8, '2026-05-02', '2026-05-07'));
        // Overtime occupies the day against a second overtime request, not against a report.
        $this->assertSame('overtime', PortalSubmittedReportPresenter::occupancy('Overtime', 4, null, null));
        $this->assertNull(PortalSubmittedReportPresenter::occupancy('Holiday Work', 4, null, null));
        $this->assertNull(PortalSubmittedReportPresenter::occupancy(null, 4, null, null));
        $this->assertTrue(PortalSubmittedReportPresenter::isCancelled('Cancelled'));
        $this->assertFalse(PortalSubmittedReportPresenter::isCancelled('Pending'));
        $this->assertFalse(PortalSubmittedReportPresenter::isCancelled('Rejected'));
        $this->assertSame(['2026-05-27', '2026-06-01'], PortalSubmittedReportPresenter::uniqueDates([
            '2026-06-01',
            '2026-05-27T00:00:00',
            '2026-06-01',
            'nope',
        ]));
    }
}
