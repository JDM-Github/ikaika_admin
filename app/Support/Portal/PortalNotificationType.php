<?php

namespace App\Support\Portal;

/**
 * Inbox type strings stored on notifications.type.
 */
final class PortalNotificationType
{
    public const REQUEST_FILED_LEAVE = 'requests.leave.filed';

    public const REQUEST_FILED_OVERTIME = 'requests.overtime.filed';

    public const REQUEST_FILED_OFFSET = 'requests.offset.filed';

    public const REQUEST_FILED_REIMBURSEMENT = 'requests.reimbursement.filed';

    public const REPORT_FILED_LATE = 'reports.late.filed';

    public const REPORT_FILED_DAILY = 'reports.daily.filed';

    // A report that waits for review on Manage / Requests' Daily Reports (2026-10-13).
    public const REPORT_TO_REVIEW = 'reports.flagged.filed';

    // A bug report came in, for whoever reads Bug Reports (2026-10-13). It names no sender.
    public const BUG_REPORTED = 'bug-reports.filed';

    // Someone changed their own employee information, for the head admins to approve (2026-10-14).
    public const PROFILE_CHANGES_TO_REVIEW = 'profile.changes.filed';

    // Their profile changes approved or rejected, for the one who asked (2026-10-14).
    public const PROFILE_CHANGES_APPROVED = 'profile.changes.approved';

    public const PROFILE_CHANGES_REJECTED = 'profile.changes.rejected';

    public const REQUEST_CANCELLED_LEAVE = 'requests.leave.cancelled';

    public const REQUEST_CANCELLED_OVERTIME = 'requests.overtime.cancelled';

    public const REQUEST_CANCELLED_OFFSET = 'requests.offset.cancelled';

    public const REQUEST_CANCELLED_REIMBURSEMENT = 'requests.reimbursement.cancelled';

    public const ROLE_CHANGED = 'manage.users.role';

    public const DEPARTMENT_CHANGED = 'manage.users.department';

    public const PROJECT_REQUESTED = 'projects.requested';

    public const PROJECT_REQUESTED_MEMBERS = 'projects.requested.members';

    public const PROJECT_APPROVED = 'projects.approved';

    public const PROJECT_REJECTED = 'projects.rejected';

    public const PROJECT_MODIFIED = 'projects.modified';

    public const CALENDAR_EVENT_CREATED = 'calendar.event.created';

    public const BIN_RESTORED = 'administration.recycle-bin.restored';

    public const EMAIL_SENT = 'administration.email.sent';
}
