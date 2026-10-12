<?php

namespace App\Support\Portal;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files a portal feature keeps on the server's private disk, never at a public link: email
 * attachments and registration documents both. One owner for the allow-list -- what may be uploaded
 * by extension, what content each extension may be detected as, and how a file is named, stored,
 * streamed back and deleted.
 */
final class PortalPrivateFiles
{
    public const DISK = 'local';

    public const MAX_NAME = 191;

    /**
     * Extension => [the type it is served as, the types its content may be detected as]. Office Open
     * XML files are zips underneath, and older Office files are compound documents, so each also
     * passes as its container; anything else -- a program, a script, a web page -- is refused
     * whatever it is named.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const TYPES = [
        'pdf' => ['application/pdf', ['application/pdf']],
        'doc' => ['application/msword', ['application/msword', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'xls' => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
        'ppt' => ['application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office']],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
        'csv' => ['text/csv', ['text/csv', 'text/plain', 'application/csv']],
        'txt' => ['text/plain', ['text/plain']],
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'png' => ['image/png', ['image/png']],
        'gif' => ['image/gif', ['image/gif']],
        'webp' => ['image/webp', ['image/webp']],
        'zip' => ['application/zip', ['application/zip', 'application/x-zip-compressed']],
    ];

    /**
     * The name it is kept and served under: the file's own, without folders, control characters or
     * the few characters a disk refuses, kept to the column with its extension intact. The content
     * is checked against the same extension's accepted types before anything is stored.
     *
     * @return array{name: string, mime: string, size: int}
     */
    public static function validated(UploadedFile $file): array
    {
        [$name, $extension] = self::name($file);
        [$mime, $accepted] = self::TYPES[$extension];
        $detected = strtolower(trim((string) $file->getMimeType()));
        if (! in_array($detected, $accepted, true)) {
            abort(422, $name.' does not look like the '.strtoupper($extension).' file its name says it is.');
        }

        return ['name' => $name, 'mime' => $mime, 'size' => (int) $file->getSize()];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function name(UploadedFile $file): array
    {
        $base = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $base)));
        $extension = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if (! array_key_exists($extension, self::TYPES)) {
            abort(422, ($clean === '' ? 'That file' : $clean).' is not a type the portal sends. Attach a PDF, Word, Excel, PowerPoint, CSV, text, image or ZIP file.');
        }

        $stem = trim(pathinfo($clean, PATHINFO_FILENAME), " .\t");
        if ($stem === '') {
            $stem = 'attachment';
        }
        $room = self::MAX_NAME - strlen($extension) - 1;
        if (mb_strlen($stem) > $room) {
            $stem = rtrim(mb_substr($stem, 0, $room));
        }

        return [$stem.'.'.$extension, $extension];
    }

    public static function store(string $directory, UploadedFile $file, string $fileName): string
    {
        $path = Storage::disk(self::DISK)->putFileAs($directory, $file, $fileName);
        if (! is_string($path) || $path === '') {
            abort(500, 'The file could not be stored. Try again.');
        }

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    // The browser must save it, never open a file sent in as something it can run.
    public static function download(string $path, string $name, string $mime): StreamedResponse
    {
        return Storage::disk(self::DISK)->download($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function megabytes(int $bytes): string
    {
        $value = number_format($bytes / (1024 * 1024), 1, '.', '');

        return (str_ends_with($value, '.0') ? substr($value, 0, -2) : $value).' MB';
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public static function serverLimitMessage(): string
    {
        return 'That file is larger than this server accepts: up to '.self::megabytes((int) UploadedFile::getMaxFilesize()).' a file.';
    }
}
