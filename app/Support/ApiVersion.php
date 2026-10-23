<?php

namespace App\Support;

/**
 * Which backend answered (2026-10-21, user): every response carries X-Api-Version - this release's VERSION, x.y.z+n,
 * under the name the project estimator's API already sends - and X-Api-Channel, the API channel (development, staging
 * or production), so the portal's Settings can say which server it is talking to. /health carries the version too.
 *
 * VERSION sits at the release's root, where deployment/build.ps1 puts it, and is read into config once: config:cache
 * runs after every deploy. A VERSION that is missing or unreadable sends no version rather than a wrong one; the
 * channel still goes.
 */
final class ApiVersion
{
    public const VERSION_HEADER = 'X-Api-Version';

    public const CHANNEL_HEADER = 'X-Api-Channel';

    public static function fromFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $text = file_get_contents($path);

        return $text === false ? null : self::fromText($text);
    }

    /**
     * The first line that is neither blank nor a # comment, as build-release reads VERSION - and only an x.y.z or x.y.z+n.
     */
    public static function fromText(string $text): ?string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            return preg_match('/^\d+\.\d+\.\d+(\+\d+)?$/', $line) === 1 ? $line : null;
        }

        return null;
    }

    public static function version(): ?string
    {
        $version = config('products.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        $headers = [];
        $version = self::version();
        if ($version !== null) {
            $headers[self::VERSION_HEADER] = $version;
        }
        $channel = trim((string) config('products.channel', ''));
        if ($channel !== '') {
            $headers[self::CHANNEL_HEADER] = $channel;
        }

        return $headers;
    }
}
