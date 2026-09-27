<?php

namespace App\Support\Portal;

/**
 * Inbox deep links. Last path segment must be a portal ShellRoute value.
 */
final class PortalShellPath
{
    public const USER_REQUESTS = '/requests/user-requests';

    public const MANAGE_REQUESTS = '/manage/manage-requests';

    public const USER_MANAGEMENT = '/manage/user-management';

    public const SUBMITTED_REPORTS = '/reports/submitted-reports';

    public const DAILY_REPORT = '/reports/daily-report';

    public const LATE_REPORT = '/reports/late-report';

    /**
     * Where an email's inbox notice may open: pages every member can reach, so a notice never links
     * a recipient to a screen they are not allowed to see.
     *
     * @var list<string>
     */
    public const EMAIL_LINKS = [
        self::DAILY_REPORT,
        self::LATE_REPORT,
        self::SUBMITTED_REPORTS,
        self::USER_REQUESTS,
    ];
}
