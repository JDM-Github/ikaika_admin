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

    public const REQUEST_CANCELLED_LEAVE = 'requests.leave.cancelled';

    public const REQUEST_CANCELLED_OVERTIME = 'requests.overtime.cancelled';

    public const REQUEST_CANCELLED_OFFSET = 'requests.offset.cancelled';

    public const REQUEST_CANCELLED_REIMBURSEMENT = 'requests.reimbursement.cancelled';

    public const ROLE_CHANGED = 'manage.users.role';

    public const BIN_RESTORED = 'administration.recycle-bin.restored';

    public const EMAIL_SENT = 'administration.email.sent';
}
