<?php

namespace App\Support\Portal;

/**
 * Daily report review (2026-10-07): which reports wait for an approver, and where a filed one stands.
 *
 * Every Engineering member's daily report waits for review on Manage / Requests' Flag Reports, which an
 * admin reaches for their own department. MIS's daily reports are approved the moment they are filed, a
 * warning or not. Any other department, and every late report, keeps the older rule: a report waits only
 * while its author is on an active warning (Flagged users). A late report answers to its late approval too.
 *
 * A report that never waited reads as approved, so nothing has to be stored for it.
 */
final class PortalReportReview
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    // Departments as PortalPreview::department() keys them.
    private const REVIEWED_DEPARTMENTS = ['engineering'];

    private const AUTO_APPROVED_DEPARTMENTS = ['mis'];

    // Whether a report filed now waits for review: its kind, its author's department, and their warning.
    public static function waits(string $kind, mixed $department, bool $isOnWarning): bool
    {
        if ($kind === PortalSubmittedReportPresenter::KIND_DAILY) {
            $key = PortalPreview::department($department);
            if (in_array($key, self::REVIEWED_DEPARTMENTS, true)) {
                return true;
            }
            if (in_array($key, self::AUTO_APPROVED_DEPARTMENTS, true)) {
                return false;
            }
        }

        return $isOnWarning;
    }

    // Where a filed report stands. A late one stands at the worse of its review and its late approval.
    public static function status(string $kind, mixed $isFlag, mixed $flagStatus, mixed $lateApproval = null): string
    {
        $review = (bool) $isFlag ? self::decision($flagStatus) : self::APPROVED;
        if ($kind !== PortalSubmittedReportPresenter::KIND_LATE) {
            return $review;
        }
        $late = self::decision($lateApproval);
        foreach ([self::REJECTED, self::PENDING] as $worse) {
            if ($review === $worse || $late === $worse) {
                return $worse;
            }
        }

        return self::APPROVED;
    }

    private static function decision(mixed $status): string
    {
        return match (PortalSubmittedReportPresenter::requestStatus($status)) {
            'approved' => self::APPROVED,
            'pending' => self::PENDING,
            default => self::REJECTED,
        };
    }
}
