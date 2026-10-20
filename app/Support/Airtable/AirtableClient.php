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
 */
final class AirtableClient
{
    private const BASE_URL = 'https://api.airtable.com/v0';

    private const SPACING_MICROSECONDS = 220000;

    private const LOCKOUT_SECONDS = 30;

    /**
     * Every table's fields as name => {type, choices}, choices only for select fields.
     *
     * @return array<string, array<string, array{type: string, choices: list<string>|null}>>
     */
    public function tables(): array
    {
        $body = $this->send('get', '/meta/bases/'.$this->base().'/tables')->json();

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
     * @param  array<string, mixed>  $fields
     */
    public function create(string $table, array $fields): string
    {
        $body = $this->send('post', '/'.$this->base().'/'.rawurlencode($table), [
            'fields' => $fields,
            'typecast' => false,
        ])->json();

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
    public function update(string $table, string $recordId, array $fields): void
    {
        $response = $this->send('patch', '/'.$this->base().'/'.rawurlencode($table).'/'.rawurlencode($recordId), [
            'fields' => $fields,
            'typecast' => false,
        ], true);

        if ($response->failed()) {
            if (! $this->exists($table, $recordId)) {
                throw new AirtableRecordGone('The Airtable record '.$recordId.' no longer exists.');
            }
            throw $this->failure($response);
        }
    }

    // Already gone counts as deleted: a retry after a half-finished run must not fail on it.
    public function delete(string $table, string $recordId): void
    {
        $response = $this->send('delete', '/'.$this->base().'/'.rawurlencode($table).'/'.rawurlencode($recordId), [], true);

        if ($response->failed() && $this->exists($table, $recordId)) {
            throw $this->failure($response);
        }
    }

    /**
     * Airtable answers a missing record and a missing permission with the same 403, so whether a
     * record is really gone is asked with a read, which the token can make either way.
     */
    public function exists(string $table, string $recordId): bool
    {
        // Only a well-formed id goes into the formula; anything else is never declared gone.
        if (preg_match('/^rec[A-Za-z0-9]{14}$/', $recordId) !== 1) {
            return true;
        }
        $query = http_build_query(['filterByFormula' => "RECORD_ID()='".$recordId."'", 'maxRecords' => 1]);
        $records = $this->send('get', '/'.$this->base().'/'.rawurlencode($table).'?'.$query)->json('records');

        return is_array($records) && $records !== [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, array $payload = [], bool $returnNotFound = false): Response
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            Sleep::usleep(self::SPACING_MICROSECONDS);
            $response = match ($method) {
                'get' => $this->request()->get(self::BASE_URL.$path),
                'delete' => $this->request()->delete(self::BASE_URL.$path),
                default => $this->request()->{$method}(self::BASE_URL.$path, $payload),
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

    private function request(): PendingRequest
    {
        $token = config('services.airtable.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('AIRTABLE_TOKEN is not set.');
        }

        // PHP on Windows ships no CA bundle; the same fix PortalCloudinary uses for its HTTPS.
        return Http::withToken($token)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->acceptJson()
            ->timeout(30);
    }

    private function base(): string
    {
        $base = config('services.airtable.base');
        if (! is_string($base) || preg_match('/^app[A-Za-z0-9]{14}$/', $base) !== 1) {
            throw new RuntimeException('AIRTABLE_BASE_ID is not set to a valid base id.');
        }

        return $base;
    }
}
