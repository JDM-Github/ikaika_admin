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
}
