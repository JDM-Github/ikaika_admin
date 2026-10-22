<?php

namespace App\Support\Airtable;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * The only class that talks to the Airtable Web API.
 *
 * Airtable allows 5 requests per second per base and answers a burst with 429 plus a 30 second
 * lockout, so every call is spaced and a 429 waits once before retrying. typecast stays off: an
 * unknown select option must fail loudly rather than quietly become a new choice in the base.
 *
 * Every call names a base key: main (Users & Projects), calendar, clients or tracker. One PAT may
 * hold all of them; a per-base token can override it.
 */
final class AirtableClient
{
    private const BASE_URL = 'https://api.airtable.com/v0';

    private const SPACING_MICROSECONDS = 220000;

    private const LOCKOUT_SECONDS = 30;

    private const BASE_ENVS = [
        'main' => 'AIRTABLE_BASE_ID',
        'calendar' => 'AIRTABLE_CALENDAR',
        'clients' => 'AIRTABLE_CLIENT_INFORMATION',
        'tracker' => 'AIRTABLE_TRANSACTION_TRACKER',
    ];

    /**
     * Every table's fields as name => {type, choices}, choices only for select fields.
     *
     * @return array<string, array<string, array{type: string, choices: list<string>|null}>>
     */
    public function tables(string $base = 'main'): array
    {
        $body = $this->send('get', '/meta/bases/'.$this->baseId($base).'/tables', [], false, $base)->json();

        $tables = [];
        foreach (is_array($body['tables'] ?? null) ? $body['tables'] : [] as $table) {
            $fields = [];
            foreach (is_array($table['fields'] ?? null) ? $table['fields'] : [] as $field) {
                $choices = $field['options']['choices'] ?? null;
                $fields[(string) $field['name']] = [
                    'type' => (string) $field['type'],
                    'choices' => is_array($choices) ? array_values(array_map(
                        static fn (array $choice): string => (string) $choice['name'],
                        $choices,
                    )) : null,
                ];
            }
            $tables[(string) $table['name']] = $fields;
        }

        return $tables;
    }

    /**
     * Every record in a table, following Airtable's offset pages. Read-only: used by the Calendar
     * pull for holidays and earn codes.
     *
     * @return list<array{id: string, fields: array<string, mixed>}>
     */
    public function records(string $table, string $base = 'main'): array
    {
        $records = [];
        $offset = null;
        do {
            $query = ['pageSize' => 100];
            if (is_string($offset) && $offset !== '') {
                $query['offset'] = $offset;
            }
            $body = $this->send('get', '/'.$this->baseId($base).'/'.rawurlencode($table).'?'.http_build_query($query), [], false, $base)->json();
            foreach (is_array($body['records'] ?? null) ? $body['records'] : [] as $record) {
                $id = $record['id'] ?? null;
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $records[] = [
                    'id' => $id,
                    'fields' => is_array($record['fields'] ?? null) ? $record['fields'] : [],
                ];
            }
            $offset = $body['offset'] ?? null;
        } while (is_string($offset) && $offset !== '');

        return $records;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function create(string $table, array $fields, string $base = 'main'): string
    {
        $body = $this->send('post', '/'.$this->baseId($base).'/'.rawurlencode($table), [
            'fields' => $fields,
            'typecast' => false,
        ], false, $base)->json();

        $id = $body['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Airtable created a record but returned no id.');
        }

        return $id;
    }

    /**
     * PATCH, never PUT: a PUT clears every field the request leaves out.
     *
     * @param  array<string, mixed>  $fields
     */
    public function update(string $table, string $recordId, array $fields, string $base = 'main'): void
    {
        $response = $this->send('patch', '/'.$this->baseId($base).'/'.rawurlencode($table).'/'.rawurlencode($recordId), [
            'fields' => $fields,
            'typecast' => false,
        ], true, $base);

        if ($response->failed()) {
            if (! $this->exists($table, $recordId, $base)) {
                throw new AirtableRecordGone('The Airtable record '.$recordId.' no longer exists.');
            }
            throw $this->failure($response);
        }
    }

    // Already gone counts as deleted: a retry after a half-finished run must not fail on it.
    public function delete(string $table, string $recordId, string $base = 'main'): void
    {
        $response = $this->send('delete', '/'.$this->baseId($base).'/'.rawurlencode($table).'/'.rawurlencode($recordId), [], true, $base);

        if ($response->failed() && $this->exists($table, $recordId, $base)) {
            throw $this->failure($response);
        }
    }

    /**
     * Airtable answers a missing record and a missing permission with the same 403, so whether a
     * record is really gone is asked with a read, which the token can make either way.
     */
    public function exists(string $table, string $recordId, string $base = 'main'): bool
    {
        // Only a well-formed id goes into the formula; anything else is never declared gone.
        if (preg_match('/^rec[A-Za-z0-9]{14}$/', $recordId) !== 1) {
            return true;
        }
        $query = http_build_query(['filterByFormula' => "RECORD_ID()='".$recordId."'", 'maxRecords' => 1]);
        $records = $this->send('get', '/'.$this->baseId($base).'/'.rawurlencode($table).'?'.$query, [], false, $base)->json('records');

        return is_array($records) && $records !== [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload = [], bool $returnNotFound = false, string $base = 'main'): Response
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            Sleep::usleep(self::SPACING_MICROSECONDS);
            $response = match ($method) {
                'get' => $this->request($base)->get(self::BASE_URL.$path),
                'delete' => $this->request($base)->delete(self::BASE_URL.$path),
                default => $this->request($base)->{$method}(self::BASE_URL.$path, $payload),
            };

            if ($response->status() === 429 && $attempt === 1) {
                Sleep::sleep(self::LOCKOUT_SECONDS);

                continue;
            }

            // A missing record and a missing permission share these; the caller tells them apart.
            if ($returnNotFound && in_array($response->status(), [403, 404], true)) {
                return $response;
            }

            // A gateway that gave up on a create may still have let it land: the caller holds it, never resends blind.
            if ($method === 'post' && in_array($response->status(), [502, 504], true)) {
                throw new ConnectionException('Airtable answered '.$response->status().' to a create; it may have landed.');
            }

            if ($response->failed()) {
                throw $this->failure($response);
            }

            return $response;
        }

        throw new RuntimeException('Airtable rate limit did not clear after one retry.');
    }

    // Airtable's own error type and message only; the request, and so the token, never.
    private function failure(Response $response): RuntimeException
    {
        $type = $response->json('error.type') ?? $response->json('error') ?? 'HTTP_'.$response->status();
        $message = $response->json('error.message') ?? '';

        return new RuntimeException(trim((is_string($type) ? $type : 'HTTP_'.$response->status()).' '.(is_string($message) ? $message : '')));
    }

    private function request(string $base = 'main'): PendingRequest
    {
        $token = $this->token($base);

        // PHP on Windows ships no CA bundle; the same fix PortalCloudinary uses for its HTTPS.
        return Http::withToken($token)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->acceptJson()
            ->timeout(30);
    }

    private function token(string $base): string
    {
        $token = $base === 'main'
            ? config('services.airtable.token')
            : config('services.airtable.tokens.'.$base);
        if (! is_string($token) || $token === '') {
            $token = config('services.airtable.token');
        }
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('AIRTABLE_TOKEN is not set.');
        }

        return $token;
    }

    private function baseId(string $base): string
    {
        $value = $base === 'main'
            ? config('services.airtable.base')
            : config('services.airtable.bases.'.$base);
        $env = self::BASE_ENVS[$base] ?? 'AIRTABLE_BASE_ID';
        if (! is_string($value) || preg_match('/^app[A-Za-z0-9]{14}$/', $value) !== 1) {
            throw new RuntimeException($env.' is not set to a valid base id.');
        }

        return $value;
    }
}
