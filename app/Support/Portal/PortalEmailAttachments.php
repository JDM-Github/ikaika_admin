<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

    private const DIRECTORY = 'email-attachments';

    // A staged file older than this belongs to a draft that was never sent.
    private const STAGED_HOURS = 24;

    // What one person may hold staged at once, across every draft they have open.
    private const MAX_STAGED = 30;

    private const TOKEN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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
        $valid = PortalPrivateFiles::validated($file);

        $this->prune();
        $actorId = (int) $actor->getKey();
        if ($this->table()->where('uploaded_by', $actorId)->whereNull('message_id')->count() >= self::MAX_STAGED) {
            abort(422, 'You have too many attachments waiting to be sent. Remove some before attaching more.');
        }

        $token = (string) Str::uuid();
        $path = PortalPrivateFiles::store(self::DIRECTORY, $file, $token);

        $this->table()->insert([
            'token' => $token,
            'message_id' => null,
            'name' => $valid['name'],
            'mime' => $valid['mime'],
            'size_bytes' => $valid['size'],
            'path' => $path,
            'uploaded_by' => $actorId,
            'date_created' => $this->timezone->now()->toDateTimeString(),
        ]);

        return [
            'section' => 'administration',
            'resource' => 'email-attachments',
            'data' => $this->present($token, $valid['name'], $valid['mime'], $valid['size']),
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
        PortalPrivateFiles::delete((string) $row->path);
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
            abort(422, 'The attachments come to more than '.PortalPrivateFiles::megabytes(self::MAX_TOTAL_BYTES).'. Remove some to send.');
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
        if (! PortalPrivateFiles::exists((string) $row->path)) {
            abort(404, 'That attachment is no longer kept on the server.');
        }

        return [
            'response' => PortalPrivateFiles::download((string) $row->path, (string) $row->name, (string) $row->mime),
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
            PortalPrivateFiles::delete((string) $row->path);
        }
        $this->table()->whereIn('id', $rows->pluck('id')->all())->delete();

        return $rows->count();
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
            if ($length > 0 && $length > PortalPrivateFiles::iniBytes((string) ini_get('post_max_size'))) {
                abort(413, PortalPrivateFiles::serverLimitMessage());
            }
            abort(422, 'Choose a file to attach.');
        }
        if (! $file instanceof UploadedFile) {
            abort(422, 'Attach one file at a time.');
        }
        if (! $file->isValid()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                abort(422, PortalPrivateFiles::serverLimitMessage());
            }
            abort(422, 'The file did not finish uploading. Try again.');
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            abort(422, 'That file is empty.');
        }
        if ($size > self::MAX_TOTAL_BYTES) {
            abort(422, 'A file can be up to '.PortalPrivateFiles::megabytes(self::MAX_TOTAL_BYTES).': every recipient gets a copy, so the whole email is held to that.');
        }

        return $file;
    }

    /**
     * @return array{id: string, name: string, mimeType: string, sizeBytes: int}
     */
    private function present(string $token, string $name, string $mime, int $size): array
    {
        return ['id' => $token, 'name' => $name, 'mimeType' => $mime, 'sizeBytes' => $size];
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
