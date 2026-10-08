<?php

namespace App\Support\Portal;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A full account of one test send by `php artisan portal:mail-test`, written to
 * storage/logs/mail-test.log: which process sent it, the mail settings it actually ran with, the .env
 * lines they came from, and the whole SMTP conversation.
 *
 * The password never appears. What decides whether it is right does: whether it is set, its length,
 * stray quotes or spaces at either end, and whether the running config still matches the .env. The
 * AUTH exchange in the transcript is redacted for the same reason.
 */
final class PortalMailDiagnostics
{
    public const LOG_PATH = 'logs/mail-test.log';

    public static function record(string $origin, string $to, ?SentMessage $sent, ?Throwable $error): void
    {
        $mailer = (string) config('mail.default');
        $settings = (array) config("mail.mailers.{$mailer}", []);
        $password = config("mail.mailers.{$mailer}.password");

        $lines = [
            '===== mail test from '.$origin.' at '.now()->toDateTimeString().' =====',
            'process        : '.PHP_SAPI.' (pid '.getmypid().')',
            'app            : env '.(string) config('app.env').', url '.(string) config('app.url'),
            'config cached  : '.(app()->configurationIsCached() ? 'YES (run php artisan config:clear)' : 'no'),
            'mailer         : '.$mailer,
            'scheme         : '.self::shown($settings['scheme'] ?? null),
            'host           : '.self::shown($settings['host'] ?? null),
            'port           : '.self::shown($settings['port'] ?? null),
            'username       : '.self::shown($settings['username'] ?? null),
            'password       : '.self::passwordShape($password),
            'password = .env: '.(is_string($password) && $password === env('MAIL_PASSWORD') ? 'yes' : 'NO (the running config is not the .env value)'),
            'EHLO domain    : '.self::shown($settings['local_domain'] ?? null),
            'relay endpoint : '.self::shown($settings['endpoint'] ?? null),
            'relay key      : '.($mailer === 'wprelay' ? self::passwordShape($settings['key'] ?? null) : '(not the relay)'),
            'timeout        : '.self::shown($settings['timeout'] ?? null),
            'from           : '.self::shown(config('mail.from.address')).' "'.(string) config('mail.from.name').'"',
            'to             : '.$to,
            '.env MAIL lines: '.self::envLines(),
        ];

        if ($error !== null) {
            $lines[] = 'result         : FAILED '.$error::class.': '.$error->getMessage();
        } else {
            $lines[] = 'result         : accepted '.(PortalEmailMessages::acceptance($sent) ?? '(no 250 line reported)');
            $lines[] = 'message id     : '.(string) $sent?->getMessageId();
        }

        $transcript = $error !== null && method_exists($error, 'getDebug') ? (string) $error->getDebug() : (string) $sent?->getDebug();
        $lines[] = '--- SMTP transcript (AUTH redacted) ---';
        $lines[] = self::redacted($transcript);
        $lines[] = '';

        // mail.diagnostics_path lets a test write somewhere other than the real report.
        $path = (string) config('mail.diagnostics_path', storage_path(self::LOG_PATH));
        Log::build(['driver' => 'single', 'path' => $path])->info(implode(PHP_EOL, $lines));
    }

    /**
     * The client's side of an AUTH exchange carries the login in base64, so every line it sends from
     * AUTH until the server's answer is replaced; the server's own prompts and answer stay.
     */
    public static function redacted(string $transcript): string
    {
        $out = [];
        $inAuth = false;
        foreach (preg_split('/\R/', $transcript) ?: [] as $line) {
            if (preg_match('/^>\s*AUTH\s+(\S+)/i', $line, $match) === 1) {
                $out[] = '> AUTH '.$match[1].' [redacted]';
                $inAuth = true;

                continue;
            }
            if ($inAuth && str_starts_with(ltrim($line), '>')) {
                $out[] = '> [redacted]';

                continue;
            }
            if ($inAuth && preg_match('/^<\s*(235|[45]\d\d)/', $line) === 1) {
                $inAuth = false;
            }
            $out[] = $line;
        }

        return trim(implode(PHP_EOL, $out));
    }

    public static function passwordShape(mixed $password): string
    {
        if (! is_string($password) || $password === '') {
            return 'NOT SET';
        }
        $notes = [];
        if ($password !== trim($password)) {
            $notes[] = 'SPACE at an end';
        }
        if (preg_match('/^[\'"]|[\'"]$/', $password) === 1) {
            $notes[] = 'QUOTE kept at an end (quoted twice in .env?)';
        }
        if (str_contains($password, '#')) {
            $notes[] = 'contains # (must be quoted in .env)';
        }

        return 'set, '.strlen($password).' characters'.($notes === [] ? '' : ', '.implode(', ', $notes));
    }

    private static function shown(mixed $value): string
    {
        return $value === null || $value === '' ? '(not set)' : (string) $value;
    }

    // Which MAIL_ names the .env defines and on which lines; a name twice means the first one wins.
    private static function envLines(): string
    {
        $path = base_path('.env');
        if (! is_readable($path)) {
            return '(no .env)';
        }
        $seen = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (preg_match('/^\s*(MAIL_[A-Z_]+)\s*=/', $line, $match) === 1) {
                $seen[$match[1]][] = $index + 1;
            }
        }
        $parts = [];
        foreach ($seen as $name => $numbers) {
            $parts[] = $name.' line '.implode('+', $numbers).(count($numbers) > 1 ? ' (TWICE: line '.$numbers[0].' wins)' : '');
        }

        return $parts === [] ? '(none)' : implode('; ', $parts);
    }
}
