<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Log copy the portal UI can swap. `{UserName|You}` is the owner of that log
 * row; other people are written as their real names. Dates are weekday, month day, year.
 */
final class PortalActivityCopy
{
    public const YOU = '{UserName|You}';

    public const YOUR = '{UserName|Your}';

    public static function displayName(Employee $employee): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($employee->first_name) ? $employee->first_name : null,
            is_string($employee->last_name) ? $employee->last_name : null,
        );
    }

    public static function date(string $ymd): string
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $ymd);
        if ($parsed === false) {
            return $ymd;
        }

        return $parsed->format('l, F j, Y');
    }

    public static function span(string $from, string $to): string
    {
        if ($from === $to) {
            return self::date($from);
        }

        return 'from '.self::date($from).' to '.self::date($to);
    }

    public static function reportKind(string $kind): string
    {
        return $kind === PortalSubmittedReportPresenter::KIND_LATE ? 'late report' : 'daily report';
    }

    public static function leaveType(string $type): string
    {
        $label = trim(preg_replace('/^\d+\s+/', '', $type) ?? $type);

        return $label !== '' ? $label : 'leave';
    }

    public static function addedReport(string $kind, string $date): string
    {
        return self::YOU.' added a '.self::reportKind($kind).' for '.self::date($date);
    }

    public static function updatedReport(string $kind, string $date): string
    {
        return self::YOU.' updated a '.self::reportKind($kind).' for '.self::date($date);
    }

    public static function deletedReport(string $kind, string $date): string
    {
        return self::YOU.' deleted a '.self::reportKind($kind).' for '.self::date($date);
    }

    public static function restoredReport(string $kind, string $date): string
    {
        return self::YOU.' restored a '.self::reportKind($kind).' for '.self::date($date);
    }

    public static function filedLeave(string $type, string $from, string $to): string
    {
        return self::YOU.' filed a '.self::leaveType($type).' request '.self::span($from, $to);
    }

    public static function updatedLeave(string $type, string $date): string
    {
        return self::YOU.' updated a '.self::leaveType($type).' request for '.self::date($date);
    }

    public static function cancelledLeave(string $type, string $date): string
    {
        return self::YOU.' cancelled a '.self::leaveType($type).' request for '.self::date($date);
    }

    public static function reviewedRequestStatuses(int $count): string
    {
        $label = $count === 1 ? '1 request status' : $count.' request statuses';

        return self::YOU.' reviewed '.$label;
    }

    public static function filedOvertime(string $date): string
    {
        return self::YOU.' filed an overtime request for '.self::date($date);
    }

    public static function updatedOvertime(string $date): string
    {
        return self::YOU.' updated an overtime request for '.self::date($date);
    }

    public static function cancelledOvertime(string $date): string
    {
        return self::YOU.' cancelled an overtime request for '.self::date($date);
    }

    public static function filedOffset(string $workDate, string $dayOffDate): string
    {
        return self::YOU.' filed an offset request to work on '.self::date($workDate)
            .' and take '.self::date($dayOffDate).' off';
    }

    public static function updatedOffset(string $workDate, string $dayOffDate): string
    {
        return self::YOU.' updated an offset request to work on '.self::date($workDate)
            .' and take '.self::date($dayOffDate).' off';
    }

    public static function cancelledOffset(string $workDate, string $dayOffDate): string
    {
        return self::YOU.' cancelled an offset request for '.self::date($workDate);
    }

    public static function filedReimbursement(string $date): string
    {
        return self::YOU.' filed a reimbursement request for '.self::date($date);
    }

    public static function updatedReimbursement(string $date): string
    {
        return self::YOU.' updated a reimbursement request for '.self::date($date);
    }

    public static function cancelledReimbursement(string $date): string
    {
        return self::YOU.' cancelled a reimbursement request for '.self::date($date);
    }

    public static function changedSomeoneRole(Employee $target, string $role): string
    {
        return self::YOU.' changed '.self::displayName($target)."'s role to ".$role;
    }

    public static function ownRoleChanged(Employee $actor, string $role): string
    {
        return self::YOUR.' role was changed to '.$role.' by '.self::displayName($actor);
    }

    public static function signedIn(): string
    {
        return self::YOU.' signed in to the portal';
    }

    public static function createdEvent(string $title, string $from, string $to): string
    {
        $trimmed = trim($title);
        $label = $trimmed !== '' ? $trimmed : 'event';

        return self::YOU.' added '.$label.' '.self::span($from, $to);
    }

    public static function notifyFiled(string $actorName, string $kind, string $from, string $to): string
    {
        return $actorName.' filed a '.$kind.' '.self::span($from, $to).'.';
    }

    public static function notifyCancelled(string $actorName, string $kind, string $date): string
    {
        return $actorName.' cancelled a '.$kind.' for '.self::date($date).'.';
    }

    public static function notifyDecided(string $kind, string $date, string $verb, string $by): string
    {
        return 'Your '.$kind.' for '.self::date($date).' was '.$verb.' by '.$by.'.';
    }

    public static function notifyRoleChanged(string $role, string $by): string
    {
        return 'Your portal role was changed to '.$role.' by '.$by.'.';
    }

    public static function notifyReportRestored(string $kind, string $date, string $by): string
    {
        return 'Your '.self::reportKind($kind).' for '.self::date($date).' was restored by '.$by.'.';
    }

    public static function createdEmailBlock(string $name, string $kind): string
    {
        return self::YOU.' created the '.self::emailBlockKind($kind).' '.$name;
    }

    public static function updatedEmailBlock(string $name, string $kind): string
    {
        return self::YOU.' updated the '.self::emailBlockKind($kind).' '.$name;
    }

    public static function deletedEmailBlock(string $name, string $kind): string
    {
        return self::YOU.' deleted the '.self::emailBlockKind($kind).' '.$name;
    }

    public static function sentEmail(string $subject, int $count): string
    {
        $label = $count === 1 ? '1 member' : $count.' members';

        return self::YOU.' sent "'.$subject.'" to '.$label;
    }

    public static function emailBlockKind(string $kind): string
    {
        return $kind === PortalEmail::KIND_FOOTER ? 'footer' : 'template';
    }

    public static function notifyEmailArrived(string $subject, string $by): string
    {
        return $by.' sent an email to the portal: '.$subject.'.';
    }
}
