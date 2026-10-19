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
 * one project. The credentials go only to the configured site, and an error carries Jira's status and its
 * own message, never the request -- so never the token or the email.
 *
 * Reads are retried on a dropped connection, a 429 or a 5xx; creating an issue and attaching a file are
 * not, because Jira takes no idempotency key and a retry could file the same report twice. A failed
 * create waits for the next try, which first looks for the issue by the report's own label -- the first
 * try may have made it before the answer was lost.
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

    private const ISSUE_TYPES_KEY = 'portal:jira:issue-types:';

    // The fields a sync reads; nothing else of an issue is ever fetched.
    private const SYNC_FIELDS = ['status', 'resolution', 'fixVersions'];

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
     * The env keys still blank, or a base URL that is not https.
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

        return $missing;
    }

    public function projectKey(): ?string
    {
        $key = $this->setting('project');

        return $key === null ? null : strtoupper($key);
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
     * The project's issue types a report can be filed as (no subtasks), cached for a day.
     *
     * @return list<array{id: string, name: string}>
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
                $types[] = ['id' => (string) $type['id'], 'name' => (string) $type['name']];
            }
        }
        Cache::put($key, $types, now()->addDay());

        return $types;
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
        $response = $this->answer(fn (): Response => $request->get($this->url('/rest/api/3/issue/'.rawurlencode($issueId).'?fields='.implode(',', self::SYNC_FIELDS))), false, true);

        return $response === null ? null : $this->issueFrom($response->json());
    }

    /**
     * The project's issue carrying a label, or null: how a retry finds what an unanswered create made.
     *
     * @return array{id: string, key: string, fields: array<string, mixed>}|null
     */
    public function issueLabelled(string $label): ?array
    {
        $jql = 'project = "'.$this->projectKey().'" AND labels = "'.$label.'" ORDER BY created ASC';
        $body = $this->read('GET', '/rest/api/3/search/jql?'.http_build_query(['jql' => $jql, 'fields' => implode(',', self::SYNC_FIELDS), 'maxResults' => 1]))->json();
        $first = is_array($body['issues'] ?? null) ? ($body['issues'][0] ?? null) : null;

        return is_array($first) ? $this->issueFrom($first) : null;
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
        $body = $this->read('POST', '/rest/api/3/issue/bulkfetch', ['issueIdsOrKeys' => array_values($issueIds), 'fields' => self::SYNC_FIELDS])->json();
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
        $url = $this->base().$path;
        if (! str_starts_with($url, $this->base().'/')) {
            throw new RuntimeException('That address is outside the Jira site.');
        }

        return $url;
    }

    private function base(): ?string
    {
        $base = $this->setting('base_url');

        return $base === null ? null : rtrim($base, '/');
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
