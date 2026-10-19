<?php

namespace App\Support\Portal;

/**
 * Inbox deep links. Last path segment must be a portal ShellRoute value.
 */
final class PortalShellPath
{
    public const USER_REQUESTS = '/requests/user-requests';

    public const MANAGE_REQUESTS = '/manage/manage-requests';

    public const MANAGE_REPORTS = '/manage/manage-reports';

    public const USER_MANAGEMENT = '/manage/user-management';

    public const MANAGE_PROJECTS = '/manage/manage-projects';

    public const VIEW_PROJECTS = '/projects/projects';

    public const CREATED_PROJECTS = '/projects/created-projects';

    public const SUBMITTED_REPORTS = '/reports/submitted-reports';

    public const DAILY_REPORT = '/reports/daily-report';

    public const LATE_REPORT = '/reports/late-report';

    // A top-level page, so it has no group in front of it.
    public const EVENT_CALENDAR = '/event-calendar';

    public const BUG_REPORTS = '/bug-reports';

    public const SETTINGS = '/settings';

    // The person's own details and documents, a sidebar row of its own.
    public const PROFILE = '/profile';

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
