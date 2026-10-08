<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The one door to the Bitbucket Cloud REST API: a repository's commits page by page (every branch, newest
 * first, like git log --all) and one commit's diffstat. The token goes only to the API's own host, so a
 * paging link pointing anywhere else is refused rather than followed.
 */
final class PortalBitbucketClient
{
    private const PAGE_LENGTH = 100;

    /**
     * One page of commits and the link to the next, or null on the last page.
     *
     * @return array{values: list<array<string, mixed>>, next: ?string}
     */
    public function commitsPage(string $workspace, string $slug, ?string $next = null): array
    {
        $url = $next ?? $this->base().'/repositories/'.rawurlencode($workspace).'/'.rawurlencode($slug).'/commits?pagelen='.self::PAGE_LENGTH;

        return $this->page($url);
    }

    /**
     * Each file one commit changed against its first parent, with its path and lines added and removed.
     *
     * @return list<array{path: string, added: int, removed: int}>
     */
    public function diffstat(string $workspace, string $slug, string $hash): array
    {
        $files = [];
        $url = $this->base().'/repositories/'.rawurlencode($workspace).'/'.rawurlencode($slug).'/diffstat/'.rawurlencode($hash).'?pagelen=500';
        while ($url !== null) {
            $page = $this->page($url);
            foreach ($page['values'] as $entry) {
                $path = $entry['new']['path'] ?? $entry['old']['path'] ?? null;
                $files[] = [
                    'path' => is_string($path) ? $path : '',
                    'added' => (int) ($entry['lines_added'] ?? 0),
                    'removed' => (int) ($entry['lines_removed'] ?? 0),
                ];
            }
            $url = $page['next'];
        }

        return $files;
    }

    /**
     * @return array{values: list<array<string, mixed>>, next: ?string}
     */
    private function page(string $url): array
    {
        if (! str_starts_with($url, $this->base().'/')) {
            throw new RuntimeException('Bitbucket sent a link outside its API.');
        }
        $response = $this->request()->get($url);
        if (! $response->successful()) {
            throw $this->failure($response);
        }
        $values = $response->json('values');
        $next = $response->json('next');

        return [
            'values' => is_array($values) ? array_values(array_filter($values, 'is_array')) : [],
            'next' => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    // The status and Bitbucket's own message only; never the request, so never the token.
    private function failure(Response $response): RuntimeException
    {
        $message = $response->json('error.message');

        return new RuntimeException('Bitbucket HTTP '.$response->status().(is_string($message) && $message !== '' ? ': '.mb_substr($message, 0, 150) : ''));
    }

    private function request(): PendingRequest
    {
        $token = config('services.bitbucket.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('BITBUCKET_TOKEN is not set.');
        }

        // PHP on Windows ships no CA bundle; the same fix the Airtable client uses.
        return Http::withToken($token)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
            ->acceptJson()
            ->timeout(30)
            // Bitbucket sometimes stalls or sheds load on one diffstat (2026-10-07): try twice more before failing the run.
            ->retry([1000, 3000], when: static fn (Throwable $error): bool => $error instanceof ConnectionException
                || ($error instanceof RequestException && ($error->response->status() === 429 || $error->response->serverError())), throw: false);
    }

    private function base(): string
    {
        return rtrim((string) config('services.bitbucket.base_url'), '/');
    }
}
