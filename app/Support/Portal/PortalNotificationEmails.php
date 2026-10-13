<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\Employee;

/**
 * The email a mapped inbox notice also sends: a letter (PortalLetters) greeting the person by name,
 * the notice's message and its link, the Company footer under it, from the portal's own address. The
 * notification_emails setting turns the whole thing off, so a local run that must not reach real
 * inboxes sends nothing, and the inbox row is written either way: a send that fails is logged and
 * dropped, never allowed to fail the write it was telling about. The email waits in the mail queue,
 * so a notice to several managers no longer holds the action for a relay round trip each.
 */
final class PortalNotificationEmails
{
    // The notices that also go by email; every other type stays in the portal.
    public const TYPES = [
        PortalNotificationType::REQUEST_FILED_LEAVE => true,
        PortalNotificationType::REQUEST_FILED_OVERTIME => true,
        PortalNotificationType::REQUEST_FILED_OFFSET => true,
        PortalNotificationType::REQUEST_FILED_REIMBURSEMENT => true,
        'requests.leave.approved' => true,
        'requests.leave.rejected' => true,
        'requests.leave.pending' => true,
        'requests.overtime.approved' => true,
        'requests.overtime.rejected' => true,
        'requests.overtime.pending' => true,
        'requests.offset.approved' => true,
        'requests.offset.rejected' => true,
        'requests.offset.pending' => true,
        'requests.reimbursement.approved' => true,
        'requests.reimbursement.rejected' => true,
        'requests.reimbursement.pending' => true,
        PortalNotificationType::PROJECT_REQUESTED => true,
        PortalNotificationType::PROJECT_REQUESTED_MEMBERS => true,
        PortalNotificationType::PROJECT_APPROVED => true,
        PortalNotificationType::PROJECT_MODIFIED => true,
        PortalNotificationType::CALENDAR_EVENT_CREATED => true,
    ];

    private const FAILED = 'portal.notification.email.failed';

    // Shared with the shared-page emails, which go out as the same letter from the same sender.
    public const FROM_NAME = 'IKAIKA Portal';

    public function send(Employee|int $employeeId, string $type, string $title, string $message, ?string $linkPath, ?string $linkLabel): void
    {
        if (! PortalAdvancedSettings::notificationEmails()) {
            return;
        }
        if (! (self::TYPES[$type] ?? false)) {
            return;
        }

        $employee = $employeeId instanceof Employee ? $employeeId : Employee::query()->find($employeeId);
        $email = $employee instanceof Employee ? strtolower(trim((string) ($employee->email ?? ''))) : '';
        if ($employee === null || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        // The inbox row is already there, so a mail that fails is only ever a warning.
        PortalLetters::send(
            $email,
            self::category($type),
            $title,
            PortalLetters::body((string) $employee->first_name, $this->message($message, $linkPath, $linkLabel)),
            [],
            self::FAILED,
            ['employee_id' => (int) $employee->getKey(), 'type' => $type],
        );
    }

    // Requests, projects and events each wear their own category; anything else is a notice.
    private static function category(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'requests.') => PortalEmailMarkup::CATEGORY_REQUEST,
            str_starts_with($type, 'projects.') => PortalEmailMarkup::CATEGORY_PROJECT,
            str_starts_with($type, 'calendar.') => PortalEmailMarkup::CATEGORY_EVENT,
            default => PortalEmailMarkup::CATEGORY_NOTICE,
        };
    }

    // The notice's own words, then its link as a button. Names and titles are people's text, so markup in them stays text.
    private function message(string $message, ?string $linkPath, ?string $linkLabel): string
    {
        $text = PortalEmailMarkup::escape($message);
        $url = $this->linkUrl($linkPath);
        $label = trim((string) $linkLabel);
        if ($url !== null && $label !== '') {
            $text .= "\n\n::button[".str_replace([']', '['], '', $label).']('.$url.')';
        }

        return $text;
    }

    // The same base PortalSharedPages::link uses; empty locally, so the link stays a path.
    private function linkUrl(?string $linkPath): ?string
    {
        $path = trim((string) $linkPath);
        if ($path === '') {
            return null;
        }
        $base = rtrim((string) config('products.catalog.portal.public_url', ''), '/');

        return ($base === '' ? '' : $base).'/'.ltrim($path, '/');
    }

    /**
     * The Company footer: the first footer block, the one the composer sends under a letter.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function companyFooter(): array
    {
        $footer = EmailBlock::query()
            ->where('kind', PortalEmail::KIND_FOOTER)
            ->orderBy('id')
            ->first();
        $body = $footer instanceof EmailBlock ? trim((string) $footer->body) : null;

        return [$body === null || $body === '' ? null : $body, PortalEmailMarkup::format($footer?->format) ?? PortalEmailMarkup::FORMAT_MARKUP];
    }
}
