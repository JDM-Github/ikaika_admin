<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The one door to Jira Cloud for Issues & Requests (2026-10-19): a service account's email and API token,
 * one project. The credentials go only to the configured site -- or, with JIRA_CLOUD_ID set for a scoped
 * token, to Atlassian's gateway for that site -- and an error carries Jira's status and its own message,
 * never the request -- so never the token or the email. A link a person opens is always the site's.
 *
 * Reads are retried on a dropped connection, a 429 or a 5xx; creating an issue and attaching a file are
 * not, because Jira takes no idempotency key and a retry could file the same report twice. A failed
 * create waits for the next try, which first looks for the issue by the report's number in its
 * description -- the first try may have made it before the answer was lost.
 *
 * MIS changing a linked report's kind or status changes the issue too (2026-10-20, user): its work type is
 * edited, or it is moved along the transition that lands in the matching column. Both are writes, sent once.
 * Triage (2026-10-21, user) adds three more: a label added or removed through update operations -- a fields
 * edit would replace every label the issue has -- and a Duplicate link made or removed.
 *
 * Every answer updates the connection the page shows: a 2xx is ok, a refused account or an unknown
 * project is refused, no answer is unreachable. A 400 is about the request, not the connection.
 */
final class PortalJiraClient
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNKNOWN = 'unknown';

    public const OK = 'ok';

    public const REFUSED = 'refused';

    public const UNREACHABLE = 'unreachable';

    private const CONNECTION_KEY = 'portal:jira:connection';

    private const GATEWAY = 'https://api.atlassian.com/ex/jira/';

    // v2 since each type keeps its level (2026-10-22), which says which of them are epics.
    private const ISSUE_TYPES_KEY = 'portal:jira:issue-types:v2:';

    private const PROJECT_KEYS_KEY = 'portal:jira:project-keys:';

    private const PLAN_FIELDS_KEY = 'portal:jira:plan-fields:';

    private const TEAMS_KEY = 'portal:jira:teams:';

    private const EPICS_KEY = 'portal:jira:epics:';

    private const CREATE_FIELDS_KEY = 'portal:jira:create-fields:';

    // The fields a sync reads; nothing else of an issue is ever fetched. Labels and links say a duplicate's (2026-10-21);
    // the parent and due date a report's plan (2026-10-22), with the site's Team and Start date fields (syncFields()).
    private const SYNC_FIELDS = ['status', 'resolution', 'fixVersions', 'issuetype', 'labels', 'issuelinks', 'parent', 'duedate'];

    // The env keys behind config('services.jira'), for saying which one is missing.
    private const SETTINGS = [
        'base_url' => 'JIRA_BASE_URL',
        'email' => 'JIRA_EMAIL',
        'token' => 'JIRA_API_TOKEN',
        'project' => 'JIRA_PROJECT_KEY',
    ];

    public function isConfigured(): bool
    {
        return $this->missingSettings() === [];
    }

    /**
     * The env keys still blank, a base URL that is not https, or a cloud id that is not one.
     *
     * @return list<string>
     */
    public function missingSettings(): array
    {
        $missing = [];
        foreach (self::SETTINGS as $key => $env) {
            if ($this->setting($key) === null) {
                $missing[] = $env;
            }
        }
        $base = $this->setting('base_url');
        if ($base !== null && ! str_starts_with($base, 'https://') && ! in_array('JIRA_BASE_URL', $missing, true)) {
            $missing[] = 'JIRA_BASE_URL';
        }
        $cloud = $this->setting('cloud_id');
        if ($cloud !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cloud) !== 1) {
            $missing[] = 'JIRA_CLOUD_ID';
        }

        return $missing;
    }

    public function projectKey(): ?string
    {
        $key = $this->setting('project');

        return $key === null ? null : strtoupper($key);
    }

    // The configured site, which keeps what is learnt of one site's people and fields apart from another's.
    public function site(): ?string
    {
        return $this->base();
    }

    // The issue as a person opens it, or null with no site configured.
    public function browseUrl(string $issueKey): ?string
    {
        $base = $this->base();

        return $base === null ? null : $base.'/browse/'.rawurlencode($issueKey);
    }

    /**
     * What the page says about the connection: not configured, or the last answer Jira gave.
     *
     * @return array{state: string, message: ?string, checkedAt: ?string}
     */
    public function connection(): array
    {
        if (! $this->isConfigured()) {
            return ['state' => self::NOT_CONFIGURED, 'message' => null, 'checkedAt' => null];
        }
        $saved = Cache::get(self::CONNECTION_KEY);
        if (! is_array($saved) || ($saved['site'] ?? null) !== $this->base().'|'.$this->projectKey()) {
            return ['state' => self::UNKNOWN, 'message' => null, 'checkedAt' => null];
        }

        return ['state' => (string) $saved['state'], 'message' => $saved['message'] ?? null, 'checkedAt' => $saved['checkedAt'] ?? null];
    }

    /**
     * @return array{accountId: string, displayName: string}
     */
    public function myself(): array
    {
        $body = $this->read('GET', '/rest/api/3/myself')->json();

        return ['accountId' => (string) ($body['accountId'] ?? ''), 'displayName' => (string) ($body['displayName'] ?? '')];
    }

    /**
     * @return array{id: string, key: string, name: string}
     */
    public function project(): array
    {
        $body = $this->read('GET', '/rest/api/3/project/'.rawurlencode((string) $this->projectKey()), missingIsRefusal: true)->json();

        return ['id' => (string) ($body['id'] ?? ''), 'key' => (string) ($body['key'] ?? ''), 'name' => (string) ($body['name'] ?? '')];
    }

    /**
     * The keys the project's issues carry: the configured one, and the one Jira files under now when the project's
     * key was changed since (2026-10-22: IPS became IP2, Jira still takes IPS, and what it files is IP2-4). Asked
     * once a day; without an answer, the configured key alone -- for a few minutes, not for every report on a page.
     *
     * @return list<string>
     */
    public function projectKeys(): array
    {
        $configured = $this->projectKey();
        if ($configured === null) {
            return [];
        }
        $cacheKey = self::PROJECT_KEYS_KEY.$configured;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $current = strtoupper($this->project()['key']);
        } catch (RuntimeException) {
            Cache::put($cacheKey, [$configured], now()->addMinutes(5));

            return [$configured];
        }
        $keys = array_values(array_unique(array_filter([$configured, $current])));
        Cache::put($cacheKey, $keys, now()->addDay());

        return $keys;
    }

    /**
     * The project's issue types a report can be filed as (no subtasks), each with its level -- 1 is an epic's --
     * cached for a day.
     *
     * @return list<array{id: string, name: string, level: int}>
     */
    public function issueTypes(): array
    {
        $key = self::ISSUE_TYPES_KEY.$this->projectKey();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $body = $this->read('GET', '/rest/api/3/issue/createmeta/'.rawurlencode((string) $this->projectKey()).'/issuetypes', missingIsRefusal: true)->json();
        $listed = $body['issueTypes'] ?? $body['values'] ?? [];
        $types = [];
        foreach (is_array($listed) ? $listed : [] as $type) {
            if (is_array($type) && ! ($type['subtask'] ?? false) && isset($type['id'], $type['name'])) {
                $types[] = ['id' => (string) $type['id'], 'name' => (string) $type['name'], 'level' => (int) ($type['hierarchyLevel'] ?? 0)];
            }
        }
        Cache::put($key, $types, now()->addDay());

        return $types;
    }

    /**
     * The ids of the site's Team and Start date fields (2026-10-22, user), which differ from site to site: as
     * JIRA_FIELD_TEAM and JIRA_FIELD_START_DATE set them, else found among the site's fields once a day;
     * without an answer, none -- for a few minutes, not for every report on a page.
     *
     * @return array{team: ?string, startDate: ?string}
     */
    public function fieldIds(): array
    {
        $set = ['team' => $this->setting('fields.team'), 'startDate' => $this->setting('fields.start_date')];
        if ($set['team'] !== null && $set['startDate'] !== null) {
            return $set;
        }
        $key = self::PLAN_FIELDS_KEY.$this->base();
        $found = Cache::get($key);
        if (! is_array($found)) {
            try {
                $found = PortalJiraIssue::planFieldIds($this->read('GET', '/rest/api/3/field')->json());
                Cache::put($key, $found, now()->addDay());
            } catch (RuntimeException) {
                $found = ['team' => null, 'startDate' => null];
                Cache::put($key, $found, now()->addMinutes(5));
            }
        }

        return ['team' => $set['team'] ?? $found['team'], 'startDate' => $set['startDate'] ?? $found['startDate']];
    }

    /**
     * The teams the Team field offers, as its suggestions name them, cached for a day; none without the field.
     *
     * @return list<array{id: string, name: string}>
     */
    public function teams(): array
    {
        $field = $this->fieldIds()['team'];
        if ($field === null) {
            return [];
        }
        $key = self::TEAMS_KEY.$this->base().'|'.$field;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $number = preg_replace('/\D+/', '', $field);
        $body = $this->read('GET', '/rest/api/3/jql/autocompletedata/suggestions?'.http_build_query(['fieldName' => 'cf['.$number.']', 'fieldValue' => '']))->json();
        $teams = PortalJiraIssue::teamsFrom($body['results'] ?? []);
        Cache::put($key, $teams, now()->addDay());

        return $teams;
    }

    // A 400 naming the Team field means the cached teams are stale: one was renamed or removed.
    public function forgetTeams(): void
    {
        $field = $this->fieldIds()['team'];
        if ($field !== null) {
            Cache::forget(self::TEAMS_KEY.$this->base().'|'.$field);
        }
    }

    /**
     * The project's epics -- the issues a report's issue can sit under -- newest first, cached for ten minutes.
     *
     * @return list<array{key: string, summary: string, isDone: bool}>
     */
    public function epics(): array
    {
        $key = self::EPICS_KEY.$this->projectKey();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $levels = array_values(array_filter($this->issueTypes(), static fn (array $type): bool => $type['level'] === 1));
        $epics = [];
        if ($levels !== []) {
            $jql = 'project = "'.$this->projectKey().'" AND issuetype in ('.implode(', ', array_column($levels, 'id')).') ORDER BY created DESC';
            $body = $this->read('GET', '/rest/api/3/search/jql?'.http_build_query(['jql' => $jql, 'fields' => 'summary,status', 'maxResults' => 100]))->json();
            $epics = PortalJiraIssue::epicsFrom($body['issues'] ?? []);
        }
        Cache::put($key, $epics, now()->addMinutes(10));

        return $epics;
    }

    // Refresh shows an epic made in Jira since, rather than in ten minutes.
    public function forgetEpics(): void
    {
        Cache::forget(self::EPICS_KEY.$this->projectKey());
    }

    /**
     * The fields an issue type's create screen has, cached for a day: a new issue is sent only those of its
     * plan the screen takes, so a project without one still takes the issue. Null when Jira cannot say.
     *
     * @return list<string>|null
     */
    public function createFields(string $issueTypeId): ?array
    {
        $key = self::CREATE_FIELDS_KEY.$this->projectKey().':'.$issueTypeId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $body = $this->read('GET', '/rest/api/3/issue/createmeta/'.rawurlencode((string) $this->projectKey()).'/issuetypes/'.rawurlencode($issueTypeId).'?maxResults=200')->json();
        } catch (RuntimeException) {
            return null;
        }
        $fields = [];
        foreach (is_array($body['fields'] ?? $body['values'] ?? null) ? ($body['fields'] ?? $body['values']) : [] as $field) {
            $id = is_array($field) ? ($field['fieldId'] ?? $field['key'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $fields[] = $id;
            }
        }
        Cache::put($key, $fields, now()->addDay());

        return $fields;
    }

    /**
     * The Jira account a work address belongs to (2026-10-22, user): the one active person a search for the
     * address finds, whose address matches wherever Jira shows it; null for none or several. Jira hides most
     * people's addresses, so the address is searched for rather than read.
     */
    public function accountByAddress(string $address): ?string
    {
        $body = $this->read('GET', '/rest/api/3/user/search?'.http_build_query(['query' => $address, 'maxResults' => 10]))->json();
        $found = [];
        foreach (is_array($body) ? $body : [] as $user) {
            if (! is_array($user) || ($user['accountType'] ?? '') !== 'atlassian' || ($user['active'] ?? false) !== true || ! is_string($user['accountId'] ?? null)) {
                continue;
            }
            $shown = $user['emailAddress'] ?? null;
            if (is_string($shown) && $shown !== '' && strcasecmp($shown, $address) !== 0) {
                continue;
            }
            $found[$user['accountId']] = true;
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * Which of these the account holds in the project: ASSIGN_ISSUES, EDIT_ISSUES, USER_PICKER and the like.
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function permissions(array $keys): array
    {
        $body = $this->read('GET', '/rest/api/3/mypermissions?'.http_build_query(['projectKey' => $this->projectKey(), 'permissions' => implode(',', $keys)]))->json();
        $held = [];
        foreach ($keys as $key) {
            $held[$key] = ($body['permissions'][$key]['havePermission'] ?? false) === true;
        }

        return $held;
    }

    // What the answers alone cannot tell: every issue asked about came back missing, so the account lost sight of them.
    public function refuse(string $message): void
    {
        $this->remember(self::REFUSED, $message);
    }

    // A 400 naming the issue type means the cached types are stale: a type was renamed or removed.
    public function forgetIssueTypes(): void
    {
        Cache::forget(self::ISSUE_TYPES_KEY.$this->projectKey());
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{id: string, key: string}
     */
    public function createIssue(array $fields): array
    {
        $body = $this->write(fn (PendingRequest $request): Response => $request->post($this->url('/rest/api/3/issue'), ['fields' => $fields]), missingIsRefusal: true)->json();
        if (! isset($body['id'], $body['key'])) {
            throw new RuntimeException('Jira made the issue but did not say which.');
        }

        return ['id' => (string) $body['id'], 'key' => (string) $body['key']];
    }

    /**
     * The moves the issue can make from where it stands, each with the status it lands in.
     *
     * @return list<array{id: string, to: array<string, mixed>}>
     */
    public function transitions(string $issueId): array
    {
        $body = $this->read('GET', '/rest/api/3/issue/'.rawurlencode($issueId).'/transitions')->json();
        $moves = [];
        foreach (is_array($body['transitions'] ?? null) ? $body['transitions'] : [] as $move) {
            if (is_array($move) && isset($move['id']) && is_array($move['to'] ?? null)) {
                $moves[] = ['id' => (string) $move['id'], 'to' => $move['to']];
            }
        }

        return $moves;
    }

    public function transition(string $issueId, string $transitionId): void
    {
        $this->write(fn (PendingRequest $request): Response => $request->post(
            $this->url('/rest/api/3/issue/'.rawurlencode($issueId).'/transitions'),
            ['transition' => ['id' => $transitionId]],
        ));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function editIssue(string $issueId, array $fields): void
    {
        $this->write(fn (PendingRequest $request): Response => $request->put(
            $this->url('/rest/api/3/issue/'.rawurlencode($issueId)),
            ['fields' => $fields],
        ));
    }

    /**
     * Labels added and removed without touching the rest: update operations, never fields.labels, which would
     * replace them all -- the portal's own label and a server's with them.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function changeLabels(string $issueId, array $add, array $remove = []): void
    {
        $operations = [
            ...array_map(fn (string $label): array => ['add' => $label], $add),
            ...array_map(fn (string $label): array => ['remove' => $label], $remove),
        ];
        if ($operations === []) {
            return;
        }
        $this->write(fn (PendingRequest $request): Response => $request->put(
            $this->url('/rest/api/3/issue/'.rawurlencode($issueId)),
            ['update' => ['labels' => $operations]],
        ));
    }

    /**
     * A link of the type between two issues, as "from" duplicates "to". Jira's request names the ends from the
     * other issue's side, so the issue the link reads from goes in inwardIssue; read back, the duplicate's own
     * links show the original as its outwardIssue. Jira takes the same link twice as one.
     */
    public function linkIssues(string $type, string $fromIssueId, string $toIssueId): void
    {
        $this->write(fn (PendingRequest $request): Response => $request->post($this->url('/rest/api/3/issueLink'), [
            'type' => ['name' => $type],
            'inwardIssue' => ['id' => $fromIssueId],
            'outwardIssue' => ['id' => $toIssueId],
        ]));
    }

    // A link removed; one Jira no longer has is gone already.
    public function deleteIssueLink(string $linkId): void
    {
        $this->answer(fn (): Response => $this->request()->delete($this->url('/rest/api/3/issueLink/'.rawurlencode($linkId))), false, true);
    }

    /**
     * The link types the site has, by name, for portal:jira-check.
     *
     * @return list<string>
     */
    public function issueLinkTypes(): array
    {
        $listed = $this->read('GET', '/rest/api/3/issueLinkType')->json('issueLinkTypes');

        return array_values(array_filter(array_map(
            fn (mixed $type): ?string => is_array($type) && is_string($type['name'] ?? null) ? $type['name'] : null,
            is_array($listed) ? $listed : [],
        )));
    }

    public function attach(string $issueId, string $fileName, string $contents, string $mimeType): void
    {
        $this->write(fn (PendingRequest $request): Response => $request
            ->withHeaders(['X-Atlassian-Token' => 'no-check'])
            ->attach('file', $contents, $fileName, ['Content-Type' => $mimeType])
            ->post($this->url('/rest/api/3/issue/'.rawurlencode($issueId).'/attachments')));
    }

    /**
     * An issue's id, key and the fields a sync reads, or null when Jira no longer has it for this account.
     *
     * @return array{id: string, key: string, fields: array<string, mixed>}|null
     */
    public function issue(string $issueId): ?array
    {
        $request = $this->retrying();
        $response = $this->answer(fn (): Response => $request->get($this->url('/rest/api/3/issue/'.rawurlencode($issueId).'?fields='.implode(',', $this->syncFields()))), false, true);

        return $response === null ? null : $this->issueFrom($response->json());
    }

    /**
     * The portal's issues whose description holds these words, oldest first, with the description to check
     * them by: how a retry finds what an unanswered create made. Jira matches words, not the exact text. The
     * labels say whose issues they may be, and a create's day keeps out any older issue (2026-10-21).
     *
     * @return list<array{id: string, key: string, fields: array<string, mixed>}>
     */
    public function issuesDescribing(string $words, string $labelsJql, ?string $createdSince = null): array
    {
        $since = $createdSince === null ? '' : ' AND created >= "'.$createdSince.'"';
        $jql = 'project = "'.$this->projectKey().'" AND '.$labelsJql.$since.' AND description ~ "\\"'.$words.'\\"" ORDER BY created ASC';
        $fields = implode(',', [...$this->syncFields(), 'description']);
        $body = $this->read('GET', '/rest/api/3/search/jql?'.http_build_query(['jql' => $jql, 'fields' => $fields, 'maxResults' => 10]))->json();
        $issues = [];
        foreach (is_array($body['issues'] ?? null) ? $body['issues'] : [] as $issue) {
            $parsed = $this->issueFrom($issue);
            if ($parsed !== null) {
                $issues[] = $parsed;
            }
        }

        return $issues;
    }

    /**
     * The fields every read asks for: the sync's own, and the site's Team and Start date fields where it has them.
     *
     * @return list<string>
     */
    private function syncFields(): array
    {
        $ids = $this->fieldIds();

        return [...self::SYNC_FIELDS, ...array_values(array_filter([$ids['team'], $ids['startDate']]))];
    }

    /**
     * Up to 100 issues by id in one call, and the ids Jira did not return -- deleted, moved out of reach,
     * or no longer visible to the account.
     *
     * @param  list<string>  $issueIds
     * @return array{issues: list<array{id: string, key: string, fields: array<string, mixed>}>, missing: list<string>}
     */
    public function bulkFetch(array $issueIds): array
    {
        $body = $this->read('POST', '/rest/api/3/issue/bulkfetch', ['issueIdsOrKeys' => array_values($issueIds), 'fields' => $this->syncFields()])->json();
        $issues = [];
        foreach (is_array($body['issues'] ?? null) ? $body['issues'] : [] as $issue) {
            $parsed = is_array($issue) ? $this->issueFrom($issue) : null;
            if ($parsed !== null) {
                $issues[] = $parsed;
            }
        }
        $found = array_column($issues, 'id');

        return ['issues' => $issues, 'missing' => array_values(array_diff($issueIds, $found))];
    }

    /**
     * @param  mixed  $body
     * @return array{id: string, key: string, fields: array<string, mixed>}|null
     */
    private function issueFrom($body): ?array
    {
        if (! is_array($body) || ! isset($body['id'], $body['key'])) {
            return null;
        }

        return ['id' => (string) $body['id'], 'key' => (string) $body['key'], 'fields' => is_array($body['fields'] ?? null) ? $body['fields'] : []];
    }

    /**
     * A request that only reads, retried.
     *
     * @param  array<string, mixed>  $json
     */
    private function read(string $method, string $path, array $json = [], bool $missingIsRefusal = false): Response
    {
        $request = $this->retrying();
        $response = $this->answer(
            fn (): Response => $method === 'GET' ? $request->get($this->url($path)) : $request->post($this->url($path), $json),
            $missingIsRefusal,
            false,
        );
        if ($response === null) {
            throw new RuntimeException('Jira gave no answer to read.');
        }

        return $response;
    }

    private function retrying(): PendingRequest
    {
        return $this->request()->retry([1000, 3000], when: static fn (Throwable $error): bool => $error instanceof ConnectionException
            || ($error instanceof RequestException && ($error->response->status() === 429 || $error->response->serverError())), throw: false);
    }

    // A request that changes Jira, sent once.
    private function write(callable $send, bool $missingIsRefusal = false): Response
    {
        $response = $this->answer(fn (): Response => $send($this->request()), $missingIsRefusal, false);
        if ($response === null) {
            throw new RuntimeException('Jira gave no answer to write.');
        }

        return $response;
    }

    // With $missingIsNull a 404 answers null rather than failing.

    private function answer(callable $send, bool $missingIsRefusal, bool $missingIsNull): ?Response
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            $this->remember(self::UNREACHABLE, 'Jira did not answer.');
            throw new RuntimeException('Jira did not answer.', 0);
        }
        if ($response->successful()) {
            $this->remember(self::OK, null);

            return $response;
        }
        if ($response->status() === 404 && $missingIsNull) {
            $this->remember(self::OK, null);

            return null;
        }
        $error = $this->failure($response);
        $status = $response->status();
        if ($status === 401 || $status === 403 || ($status === 404 && $missingIsRefusal)) {
            $this->remember(self::REFUSED, $error->getMessage());
        } elseif ($status === 429 || $response->serverError()) {
            $this->remember(self::UNREACHABLE, $error->getMessage());
        }

        throw $error;
    }

    // The status and Jira's own words only: its first error message, or the first field it refused.
    private function failure(Response $response): RuntimeException
    {
        $messages = $response->json('errorMessages');
        $errors = $response->json('errors');
        $detail = null;
        if (is_array($messages) && is_string($messages[0] ?? null) && $messages[0] !== '') {
            $detail = $messages[0];
        } elseif (is_array($errors) && $errors !== []) {
            $field = (string) array_key_first($errors);
            $detail = $field.': '.(is_string($errors[$field]) ? $errors[$field] : 'refused');
        }

        return new RuntimeException('Jira HTTP '.$response->status().($detail === null ? '' : ': '.mb_substr($detail, 0, 150)), $response->status());
    }

    private function remember(string $state, ?string $message): void
    {
        Cache::forever(self::CONNECTION_KEY, [
            'site' => $this->base().'|'.$this->projectKey(),
            'state' => $state,
            'message' => $message,
            'checkedAt' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Jira is not configured: '.implode(', ', $this->missingSettings()).'.');
        }

        // PHP on Windows ships no CA bundle; the same fix the Bitbucket and Airtable clients use.
        return Http::withBasicAuth((string) $this->setting('email'), (string) $this->setting('token'))
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    // Every request stays on the configured site, so the credentials never leave it.
    private function url(string $path): string
    {
        $url = $this->apiBase().$path;
        if (! str_starts_with($url, $this->apiBase().'/')) {
            throw new RuntimeException('That address is outside the Jira site.');
        }

        return $url;
    }

    private function base(): ?string
    {
        $base = $this->setting('base_url');

        return $base === null ? null : rtrim($base, '/');
    }

    // Where the requests go: the site itself, or the gateway for it when a cloud id is set.
    private function apiBase(): ?string
    {
        $cloud = $this->setting('cloud_id');

        return $cloud === null ? $this->base() : self::GATEWAY.strtolower($cloud);
    }

    // The id Atlassian's gateway names this site by, which the site gives anyone who asks; null without an answer.
    public function siteCloudId(): ?string
    {
        $base = $this->base();
        if ($base === null) {
            return null;
        }
        try {
            $id = Http::withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])->timeout(10)
                ->get($base.'/_edge/tenant_info')->json('cloudId');
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function setting(string $key): ?string
    {
        $value = config('services.jira.'.$key);
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
