<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Many reports changed at once from the page (2026-10-22, user). Each goes through the one-report change it stands
 * for -- a save, archive, delete or send -- under that report's own lock and rules, so a bulk change can do nothing
 * one by one could not; one refused never stops the rest, and the answer says which were and why, beside the list.
 * Deleting and sending need their own rules on top of the page's, asked once before anything changes.
 */
final class PortalBugReportsBulk
{
    public const MAX = 100;

    // What each action is called in the log, and the rule it needs beyond the page's.
    private const ACTIONS = [
        'status' => ['words' => 'moved', 'rule' => null],
        'assign' => ['words' => 'assigned', 'rule' => null],
        'unassign' => ['words' => 'unassigned', 'rule' => null],
        'kind' => ['words' => 'filed under another kind', 'rule' => null],
        'team' => ['words' => 'gave a team', 'rule' => null],
        'parent' => ['words' => 'moved under another', 'rule' => null],
        'archive' => ['words' => 'archived', 'rule' => null],
        'unarchive' => ['words' => 'brought back', 'rule' => null],
        'delete' => ['words' => 'deleted', 'rule' => PortalBugReportDeletion::RULE],
        'send' => ['words' => 'sent to Jira', 'rule' => PortalBugReportsJira::RULE],
    ];

    private const REFUSALS = [
        PortalBugReportDeletion::RULE => 'Deleting them needs Delete issues and requests.',
        PortalBugReportsJira::RULE => 'Sending them to Jira needs Send to Jira.',
    ];

    public function __construct(
        private readonly PortalBugReports $reports,
        private readonly PortalBugReportDeletion $deletion,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function change(Employee $actor, Request $request): array
    {
        $ids = $this->validatedIds($request->input('ids'));
        $action = (string) $request->input('action', '');
        if (! array_key_exists($action, self::ACTIONS)) {
            abort(422, 'Choose what to do with them.');
        }
        $rule = self::ACTIONS[$action]['rule'];
        if ($rule !== null && ! PortalRules::holds($actor, $request, $rule)) {
            abort(403, self::REFUSALS[$rule]);
        }
        $value = $request->input('value');

        $results = [];
        foreach ($ids as $id) {
            try {
                $this->one($actor, $request, $action, $id, $value);
                $results[] = ['id' => (string) $id, 'ok' => true, 'message' => null];
            } catch (HttpExceptionInterface $refused) {
                $results[] = ['id' => (string) $id, 'ok' => false, 'message' => $refused->getMessage()];
            }
        }
        $done = count(array_filter($results, static fn (array $result): bool => $result['ok']));
        $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports',
            PortalActivityCopy::bulkBugReports(self::ACTIONS[$action]['words'], $done, count($results) - $done), null, $request,
            ['action' => $action, 'ids' => array_map('strval', $ids), 'refused' => array_values(array_column(array_filter($results, static fn (array $result): bool => ! $result['ok']), 'id'))]);

        return [...$this->reports->list($request->boolean('withArchived')), 'results' => $results];
    }

    private function one(Employee $actor, Request $request, string $action, int $id, mixed $value): void
    {
        match ($action) {
            'status' => $this->reports->update($actor, $id, $this->item($request, ['status' => is_scalar($value) ? (string) $value : ''])),
            'kind' => $this->reports->update($actor, $id, $this->item($request, ['kind' => is_scalar($value) ? (string) $value : ''])),
            'team' => $this->reports->update($actor, $id, $this->item($request, ['teamId' => is_scalar($value) ? (string) $value : ''])),
            'parent' => $this->reports->update($actor, $id, $this->item($request, self::parent($value))),
            'assign', 'unassign' => $this->reports->update($actor, $id, $this->item($request, ['assigneeIds' => $this->assigneesAfter($id, $action, $value)])),
            'archive', 'unarchive' => $this->reports->archive($actor, $id, $this->item($request, ['archived' => $action === 'archive'])),
            'delete' => $this->deletion->destroy($actor, $id, $this->item($request, [])),
            'send' => $this->reports->sendToJira($actor, $id, $this->item($request, [])),
        };
    }

    /**
     * Who works on it after some are added or taken off: those already on it in their order, then those added.
     *
     * @return list<string>
     */
    private function assigneesAfter(int $id, string $action, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            abort(422, 'Choose someone in MIS to handle them.');
        }
        $named = array_values(array_unique(array_map(static fn (mixed $person): string => is_scalar($person) ? trim((string) $person) : '-', $value)));
        $first = DB::connection('portal')->table('bug_reports')->where('id', $id)->value('assignee_id');
        $now = array_map('strval', array_values(array_filter([$first === null ? null : (int) $first, ...(PortalBugReportAssignees::othersOf([$id])[$id] ?? [])])));

        return $action === 'assign'
            ? array_values(array_unique([...$now, ...$named]))
            : array_values(array_diff($now, $named));
    }

    /**
     * Under a report, by its number, or under an epic Jira alone has, by its key; nothing takes it out from under one.
     *
     * @return array<string, string>
     */
    private static function parent(mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            return ['parentReportId' => '', 'parentKey' => ''];
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9_]*-\d+$/', $text) === 1 ? ['parentKey' => $text] : ['parentReportId' => $text];
    }

    /**
     * One report's share of the change: the request as it came -- who asked, from where, in which mode -- saying
     * only what this change says.
     *
     * @param  array<string, mixed>  $body
     */
    private function item(Request $request, array $body): Request
    {
        $server = array_diff_key($request->server->all(), array_flip(['CONTENT_TYPE', 'HTTP_CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_CONTENT_LENGTH']));
        $item = Request::create($request->fullUrl(), 'PATCH', $body, $request->cookies->all(), [], $server);
        $item->attributes->add($request->attributes->all());

        return $item;
    }

    /**
     * @return list<int>
     */
    private function validatedIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            abort(422, 'Choose the issues and requests to change.');
        }
        $ids = [];
        foreach ($value as $id) {
            if (! is_scalar($id) || ! ctype_digit((string) $id)) {
                abort(422, 'Choose the issues and requests to change.');
            }
            $ids[(int) $id] = (int) $id;
        }
        if (count($ids) > self::MAX) {
            abort(422, 'Change at most '.self::MAX.' at once.');
        }

        return array_values($ids);
    }
}
