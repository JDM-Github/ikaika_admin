<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Send Email's attachments: files that ride along with a message, kept on the server's private disk
 * rather than Cloudinary, because a quotation or a payslip is not something to leave at a public link.
 *
 * A file is staged the moment it is attached -- uploaded on its own, so a send never carries the bytes
 * and a slow upload never holds the send up -- and belongs to whoever staged it until a send of theirs
 * claims it. A claimed file stays with its outbox row, so All Emails can say what went out and hand it
 * back. A staged file that is never sent is cleared after a day.
 *
 * Every copy of an email carries every file, and each member gets a copy of their own, so the whole set
 * is held to 10 MB: what mail servers take per message. What may be attached is checked by its name and
 * by its content, so a program renamed to .pdf is refused rather than mailed from the company's address.
 */
final class PortalEmailAttachments
{
    public const MAX_FILES = 10;

    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024;

    public const DISK = 'local';

    private const DIRECTORY = 'email-attachments';

    // A staged file older than this belongs to a draft that was never sent.
    private const STAGED_HOURS = 24;

    // What one person may hold staged at once, across every draft they have open.
    private const MAX_STAGED = 30;

    private const MAX_NAME = 191;

    private const TOKEN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Extension => [the type it is mailed as, the types its content may be detected as]. Office Open XML
     * files are zips underneath, and older Office files are compound documents, so each also passes as
     * its container; anything else -- a program, a script, a web page -- is refused whatever it is named.
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

    public function __construct(
        private readonly PortalTimezone $timezone,
    ) {}

    /**
     * Keeps one attached file until a send claims it, and answers with what the composer shows for it.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string, mimeType: string, sizeBytes: int}}
     */
    public function stage(Employee $actor, Request $request): array
    {
        $file = $this->validatedFile($request);
        [$name, $extension] = $this->validatedName($file);
        [$mime, $accepted] = self::TYPES[$extension];
        $detected = strtolower(trim((string) $file->getMimeType()));
        if (! in_array($detected, $accepted, true)) {
            abort(422, $name.' does not look like the '.strtoupper($extension).' file its name says it is.');
        }

        $this->prune();
        $actorId = (int) $actor->getKey();
        if ($this->table()->where('uploaded_by', $actorId)->whereNull('message_id')->count() >= self::MAX_STAGED) {
            abort(422, 'You have too many attachments waiting to be sent. Remove some before attaching more.');
        }

        $token = (string) Str::uuid();
        $path = Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, $token);
        if (! is_string($path) || $path === '') {
            abort(500, 'The attachment could not be stored. Try again.');
        }

        $size = (int) $file->getSize();
        $this->table()->insert([
            'token' => $token,
            'message_id' => null,
            'name' => $name,
            'mime' => $mime,
            'size_bytes' => $size,
            'path' => $path,
            'uploaded_by' => $actorId,
            'date_created' => $this->timezone->now()->toDateTimeString(),
        ]);

        return [
            'section' => 'administration',
            'resource' => 'email-attachments',
            'data' => $this->present($token, $name, $mime, $size),
        ];
    }

    // Takes back a staged file the sender removed from the draft. A sent one is the outbox's now.
    public function discard(Employee $actor, string $token): void
    {
        $token = strtolower(trim($token));
        $row = preg_match(self::TOKEN, $token) === 1
            ? $this->table()->where('token', $token)->where('uploaded_by', (int) $actor->getKey())->whereNull('message_id')->first(['id', 'path'])
            : null;
        if ($row === null) {
            abort(404, 'That attachment is not one of yours waiting to be sent.');
        }

        $this->table()->where('id', $row->id)->delete();
        Storage::disk(self::DISK)->delete((string) $row->path);
    }

    /**
     * The staged files a send names, checked before anything goes out: each the sender's own and not
     * yet sent, no more than ten, and 10 MB together. In the order they were attached.
     *
     * @return list<object>
     */
    public function staged(Employee $actor, mixed $tokens): array
    {
        if ($tokens === null || $tokens === []) {
            return [];
        }
        if (! is_array($tokens)) {
            abort(422, 'Attachments must be sent as a list.');
        }

        $wanted = [];
        foreach ($tokens as $token) {
            $token = strtolower(trim(is_string($token) ? $token : ''));
            if (preg_match(self::TOKEN, $token) !== 1) {
                abort(422, 'One of the attachments is not one you attached.');
            }
            $wanted[$token] = true;
        }
        $wanted = array_keys($wanted);
        if (count($wanted) > self::MAX_FILES) {
            abort(422, 'An email can carry up to '.self::MAX_FILES.' attachments.');
        }

        $rows = $this->table()
            ->whereIn('token', $wanted)
            ->where('uploaded_by', (int) $actor->getKey())
            ->whereNull('message_id')
            ->orderBy('id')
            ->get(['id', 'token', 'name', 'mime', 'size_bytes', 'path'])
            ->all();
        if (count($rows) !== count($wanted)) {
            abort(422, 'An attachment is no longer available. Remove it and attach it again.');
        }
        if (array_sum(array_map(static fn (object $row): int => (int) $row->size_bytes, $rows)) > self::MAX_TOTAL_BYTES) {
            abort(422, 'The attachments come to more than '.self::megabytes(self::MAX_TOTAL_BYTES).'. Remove some to send.');
        }

        return $rows;
    }

    /**
     * Gives the staged files to the outbox row, inside the transaction that opens it: had another send
     * claimed one a moment ago, this send is refused and its row never exists.
     *
     * @param  list<object>  $rows
     */
    public function attach(array $rows, int $messageId): void
    {
        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $free = $this->table()->whereIn('id', $ids)->whereNull('message_id')->lockForUpdate()->count();
        if ($free !== count($ids)) {
            abort(422, 'An attachment was just sent with another email. Attach it again.');
        }
        $this->table()->whereIn('id', $ids)->update(['message_id' => $messageId]);
    }

    /**
     * What the mail is built from: each file on the private disk, with the name and type it goes out as.
     *
     * @param  list<object>  $rows
     * @return list<array{path: string, name: string, mime: string}>
     */
    public static function mailFiles(array $rows): array
    {
        return array_map(static fn (object $row): array => [
            'path' => (string) $row->path,
            'name' => (string) $row->name,
            'mime' => (string) $row->mime,
        ], $rows);
    }

    /**
     * @param  list<object>  $rows
     */
    public static function totalBytes(array $rows): int
    {
        return array_sum(array_map(static fn (object $row): int => (int) $row->size_bytes, $rows));
    }

    /**
     * What one sent email carried, in the order it was attached.
     *
     * @return list<array{id: string, name: string, mimeType: string, sizeBytes: int}>
     */
    public function forMessage(int $messageId): array
    {
        return $this->table()
            ->where('message_id', $messageId)
            ->orderBy('id')
            ->get(['token', 'name', 'mime', 'size_bytes'])
            ->map(fn (object $row): array => $this->present((string) $row->token, (string) $row->name, (string) $row->mime, (int) $row->size_bytes))
            ->all();
    }

    /**
     * One file a sent email carried, as a download under its own name. The row and the message are
     * both named, so no id from one email opens another's file.
     *
     * @return array{response: StreamedResponse, name: string}
     */
    public function download(int $messageId, string $token): array
    {
        $token = strtolower(trim($token));
        $row = preg_match(self::TOKEN, $token) === 1
            ? $this->table()->where('message_id', $messageId)->where('token', $token)->first(['name', 'mime', 'path'])
            : null;
        if ($row === null) {
            abort(404, 'That attachment was not found on this email.');
        }
        if (! Storage::disk(self::DISK)->exists((string) $row->path)) {
            abort(404, 'That attachment is no longer kept on the server.');
        }

        return [
            'response' => Storage::disk(self::DISK)->download((string) $row->path, (string) $row->name, [
                'Content-Type' => (string) $row->mime,
                // The browser must save it, never open a file mailed in as something it can run.
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]),
            'name' => (string) $row->name,
        ];
    }

    // Staged files a day old go, rows and bytes. A sent file is the outbox's and stays with it.
    public function prune(): int
    {
        $cutoff = $this->timezone->now()->subHours(self::STAGED_HOURS)->toDateTimeString();
        $rows = $this->table()->whereNull('message_id')->where('date_created', '<', $cutoff)->get(['id', 'path']);
        if ($rows->isEmpty()) {
            return 0;
        }

        foreach ($rows as $row) {
            Storage::disk(self::DISK)->delete((string) $row->path);
        }
        $this->table()->whereIn('id', $rows->pluck('id')->all())->delete();

        return $rows->count();
    }

    public static function megabytes(int $bytes): string
    {
        $value = number_format($bytes / (1024 * 1024), 1, '.', '');

        return (str_ends_with($value, '.0') ? substr($value, 0, -2) : $value).' MB';
    }

    /**
     * The one file a request carries. A body past post_max_size reaches PHP empty, and a file past
     * upload_max_filesize arrives as an error, so both say what this server takes rather than that
     * nothing was chosen.
     */
    private function validatedFile(Request $request): UploadedFile
    {
        $file = $request->file('file');
        if ($file === null) {
            $length = (int) $request->server('CONTENT_LENGTH', 0);
            if ($length > 0 && $length > self::iniBytes((string) ini_get('post_max_size'))) {
                abort(413, self::serverLimitMessage());
            }
            abort(422, 'Choose a file to attach.');
        }
        if (! $file instanceof UploadedFile) {
            abort(422, 'Attach one file at a time.');
        }
        if (! $file->isValid()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                abort(422, self::serverLimitMessage());
            }
            abort(422, 'The file did not finish uploading. Try again.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            abort(422, 'That file is empty.');
        }
        if ($size > self::MAX_TOTAL_BYTES) {
            abort(422, 'A file can be up to '.self::megabytes(self::MAX_TOTAL_BYTES).': every recipient gets a copy, so the whole email is held to that.');
        }

        return $file;
    }

    /**
     * The name it goes out under: the file's own, without folders, control characters or the few
     * characters a mail client or a disk refuses, kept to the column with its extension intact.
     *
     * @return array{0: string, 1: string}
     */
    private function validatedName(UploadedFile $file): array
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

    /**
     * @return array{id: string, name: string, mimeType: string, sizeBytes: int}
     */
    private function present(string $token, string $name, string $mime, int $size): array
    {
        return ['id' => $token, 'name' => $name, 'mimeType' => $mime, 'sizeBytes' => $size];
    }

    private static function serverLimitMessage(): string
    {
        return 'That file is larger than this server accepts: up to '.self::megabytes((int) UploadedFile::getMaxFilesize()).' a file.';
    }

    // php.ini sizes as written there: "8M", "512K", "1G" or plain bytes.
    private static function iniBytes(string $value): int
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

    private function table(): Builder
    {
        return $this->connection()->table('email_attachments');
    }

    private function connection(): Connection
    {
        return Employee::query()->getConnection();
    }
}
