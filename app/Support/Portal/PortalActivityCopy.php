<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Log copy the portal UI can swap. `{UserName|You}` is the owner of that log
 * row; other people are written as their real names. Dates are weekday, month day, year.
 */
final class PortalActivityCopy
{
    public const YOU = '{UserName|You}';

    public const YOUR = '{UserName|Your}';

    /**
     * The actor's name as other people read it in an alert or in their own log: a developer's act in
     * Developer mode (past their Default powers, or done to someone else) reads "a developer", like
     * the log it goes with. Capitalised when the name opens the sentence.
     */
    public static function actorName(Employee $actor, ?Request $request, bool $capitalised = false): string
    {
        if ($request !== null && PortalDeveloperMode::isOn($actor, $request) && PortalRules::isDevelopersAct($request)) {
            return $capitalised ? PortalAccessGraph::DEVELOPER_NAME_CAPITAL : PortalAccessGraph::DEVELOPER_NAME;
        }

        return self::displayName($actor);
    }

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

    public static function previewedAirtableSync(int $count): string
    {
        return self::YOU.' previewed the Airtable sync: '.$count.' '.($count === 1 ? 'change' : 'changes').' waiting';
    }

    public static function notifyProjectRequested(string $who, string $name, bool $isResubmitted): string
    {
        return $isResubmitted
            ? $who.' sent the project '.$name.' again for approval.'
            : $who.' added the project '.$name.' and it waits for approval.';
    }

    public static function notifyProjectDecided(string $who, string $name, bool $isApproved, ?string $remarks): string
    {
        return $isApproved
            ? $who.' approved the project '.$name.'.'
            : $who.' rejected the project '.$name.($remarks === null ? '.' : ': '.$remarks);
    }

    public static function notifyProjectClosed(string $who, string $name): string
    {
        return $who.' closed the project '.$name.'. It waits for your approval.';
    }

    public static function notifyProjectReopened(string $who, string $name): string
    {
        return $who.' sent the project '.$name.' back to waiting for approval.';
    }

    public static function notifyProjectModified(string $who, string $name): string
    {
        return $who.' edited the project '.$name.'.';
    }

    public static function notifyProjectMembersAdded(string $who, string $name): string
    {
        return $who.' added you to the project '.$name.'.';
    }

    public static function notifyEventCreated(string $who, string $title, string $from, string $to): string
    {
        $trimmed = trim($title);
        $label = $trimmed !== '' ? $trimmed : 'event';

        return $who.' added the event '.$label.' '.self::span($from, $to).'.';
    }

    // Logged under the approver only: the person flagged never sees it.
    public static function flaggedMember(string $memberName): string
    {
        return self::YOU.' flagged '.$memberName.' for report review';
    }

    public static function unflaggedMember(string $memberName): string
    {
        return self::YOU.' took '.$memberName.' off report review';
    }

    public static function decidedProject(string $name, bool $isApproved): string
    {
        return self::YOU.($isApproved ? ' approved' : ' rejected').' the project '.$name;
    }

    public static function reopenedProject(string $name): string
    {
        return self::YOU.' sent the project '.$name.' back to waiting';
    }

    public static function addedProject(string $name): string
    {
        return self::YOU.' added the project '.$name;
    }

    public static function editedProject(string $name): string
    {
        return self::YOU.' edited the project '.$name;
    }

    public static function changedProjectScopes(string $name, int $count): string
    {
        return self::YOU.' set '.$count.' '.($count === 1 ? 'scope' : 'scopes').' on the project '.$name;
    }

    public static function deletedProject(string $name): string
    {
        return self::YOU.' deleted the project '.$name;
    }

    public static function restoredProject(string $name): string
    {
        return self::YOU.' restored the project '.$name;
    }

    public static function chargedReimbursement(string $memberName, string $envelopeName, string $amount): string
    {
        return self::YOU.' charged '.$amount.' to '.$envelopeName.' for '.$memberName."'s reimbursement";
    }

    public static function adjustedReimbursementCharge(string $memberName, string $envelopeName, string $from, string $to): string
    {
        return self::YOU.' moved '.$memberName."'s reimbursement charge on ".$envelopeName.' from '.$from.' to '.$to;
    }

    public static function releasedReimbursementCharge(string $envelopeName, string $amount): string
    {
        return self::YOU.' returned '.$amount.' to '.$envelopeName.' when a reimbursement stopped being approved';
    }

    public static function addedBudgetCode(string $name): string
    {
        return self::YOU.' added the budget code '.$name;
    }

    public static function addedBudgetEnvelope(string $name, ?string $codeName): string
    {
        return $codeName === null
            ? self::YOU.' added the envelope '.$name.' with no budget code'
            : self::YOU.' added the envelope '.$name.' under '.$codeName;
    }

    public static function updatedBudgetCode(string $name): string
    {
        return self::YOU.' updated the budget code '.$name;
    }

    public static function updatedBudgetEnvelope(string $name): string
    {
        return self::YOU.' updated the envelope '.$name;
    }

    public static function closedBudgetEnvelope(string $name): string
    {
        return self::YOU.' closed the envelope '.$name;
    }

    public static function reopenedBudgetEnvelope(string $name): string
    {
        return self::YOU.' reopened the envelope '.$name;
    }

    public static function allocatedEnvelopeFunds(string $envelopeName, string $amount, string $accountName): string
    {
        return self::YOU.' allocated '.$amount.' to '.$envelopeName.' from '.$accountName.'\'s unallocated funds';
    }

    public static function addedAccount(string $accountName): string
    {
        return self::YOU.' added the account '.$accountName;
    }

    public static function addedAccountFunds(string $accountName, string $amount): string
    {
        return self::YOU.' added '.$amount.' to the account '.$accountName;
    }

    // Between two accounts or two envelopes: the names say which.
    public static function transferredFunds(string $amount, string $from, string $to): string
    {
        return self::YOU.' transferred '.$amount.' from '.$from.' to '.$to;
    }

    public static function completedTransfer(string $amount, string $from, string $to): string
    {
        return self::YOU.' completed the pending transfer of '.$amount.' from '.$from.' to '.$to;
    }

    public static function cancelledTransfer(string $amount): string
    {
        return self::YOU.' cancelled a pending transfer of '.$amount;
    }

    // MIS moving an issue or request along; the sender is anonymous, so only the report is named, by its kind.
    public static function updatedBugReport(string $title, ?string $statusLabel, string $noun = 'bug report'): string
    {
        return $statusLabel === null
            ? self::YOU.' updated the '.$noun.' '.$title
            : self::YOU.' marked the '.$noun.' '.$title.' '.$statusLabel;
    }

    // Who handles an issue or request: taken by the person themselves, given to someone, or let go.
    public static function assignedBugReport(string $title, ?string $assigneeName, bool $isTaken, string $noun = 'bug report'): string
    {
        if ($isTaken) {
            return self::YOU.' took the '.$noun.' '.$title;
        }

        return $assigneeName === null
            ? self::YOU.' unassigned the '.$noun.' '.$title
            : self::YOU.' assigned the '.$noun.' '.$title.' to '.$assigneeName;
    }

    // MIS sending an issue or request to Jira by hand (2026-10-19), named by the issue it became.
    public static function sentBugReportToJira(string $title, string $noun, string $issueKey): string
    {
        return self::YOU.' sent the '.$noun.' '.$title.' to Jira as '.$issueKey;
    }

    // Every report not in Jira yet, sent at once (2026-10-20, user).
    public static function sentBugReportsToJira(int $count): string
    {
        return self::YOU.' sent '.$count.' '.($count === 1 ? 'issue or request' : 'issues and requests').' to Jira';
    }

    // The switch that sends new issues and requests to Jira on their own (2026-10-19).
    public static function switchedJiraAutoSend(bool $on): string
    {
        return self::YOU.' turned sending new issues and requests to Jira '.($on ? 'on' : 'off');
    }

    // Triage (2026-10-21, user): cancelled as won't fix, or marked a duplicate of the report it names.
    public static function cancelledBugReport(string $title, string $noun, ?string $original = null): string
    {
        return $original === null
            ? self::YOU.' cancelled the '.$noun.' '.$title." as won't fix"
            : self::YOU.' marked the '.$noun.' '.$title.' a duplicate of '.$original;
    }

    // MIS filing a report under another kind (2026-10-19): a bug that is really a feature request, say.
    public static function reclassifiedBugReport(string $title, string $fromNoun, string $asPhrase): string
    {
        return self::YOU.' filed the '.$fromNoun.' '.$title.' as '.$asPhrase;
    }

    // An amount changed reads from what it was to what it is (2026-10-13).
    public static function editedTransaction(string $before, string $after, ?string $wasAmount = null, ?string $amount = null): string
    {
        $edited = $before === $after
            ? self::YOU.' edited the transaction '.$after
            : self::YOU.' edited the transaction '.$before.', now '.$after;

        return $wasAmount === null || $amount === null ? $edited : $edited.', from '.$wasAmount.' to '.$amount;
    }

    public static function addedTransaction(string $kind, string $name, string $amount, ?string $envelopeName): string
    {
        $text = self::YOU.' added the '.strtolower($kind).' '.$name.' of '.$amount;

        return $envelopeName === null ? $text : $text.' on '.$envelopeName;
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

    public static function addedUser(Employee $employee): string
    {
        return self::YOU.' added '.self::displayName($employee).' as a user';
    }

    public static function changedSomeoneDepartment(Employee $target, string $department): string
    {
        return self::YOU.' moved '.self::displayName($target).' to the '.$department.' department';
    }

    public static function notifyDepartmentChanged(string $department, string $by): string
    {
        return 'You were moved to the '.$department.' department by '.$by.'.';
    }

    public static function ownRoleChanged(Employee $actor, string $role, ?Request $request = null): string
    {
        return self::YOUR.' role was changed to '.$role.' by '.self::actorName($actor, $request);
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

    // "filed a leave request for Monday, ..." or "filed an overtime request from ... to ...".
    public static function notifyFiled(string $actorName, string $kind, string $from, string $to): string
    {
        return $actorName.' filed '.self::article($kind).' '.$kind.' '.($from === $to ? 'for ' : '').self::span($from, $to).'.';
    }

    public static function notifyCancelled(string $actorName, string $kind, string $date): string
    {
        return $actorName.' cancelled '.self::article($kind).' '.$kind.' for '.self::date($date).'.';
    }

    private static function article(string $noun): string
    {
        return preg_match('/^[aeiou]/i', $noun) === 1 ? 'an' : 'a';
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

    public static function madeDefaultSignature(string $name): string
    {
        return self::YOU.' made the footer '.$name.' the default signature';
    }

    public static function uploadedEmailImage(bool $global = false): string
    {
        return self::YOU.' uploaded '.($global ? 'a global' : 'an').' email image';
    }

    public static function deletedEmailImage(bool $global = false): string
    {
        return self::YOU.' deleted '.($global ? 'a global' : 'an').' email image';
    }

    public static function downloadedEmailAttachment(string $name, string $subject): string
    {
        return self::YOU.' downloaded '.$name.' from the email "'.$subject.'"';
    }

    public static function editedSignatureProfile(Employee $target): string
    {
        return self::YOU.' edited the signature profile of '.self::displayName($target);
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
