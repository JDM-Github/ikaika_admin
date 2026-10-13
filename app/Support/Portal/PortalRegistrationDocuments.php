<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The documents a candidate uploads: their profile photo, their TIN, SSS, PhilHealth and HDMF IDs,
 * and their bank certificate. Files are held on the server's private disk (never a public link) and
 * served back only through the authenticated Manage / Onboarding routes. What may be uploaded is
 * checked by name and by content through PortalPrivateFiles, and each kind narrows that further: the
 * photo is an image, the others are a PDF or an image, the bank certificate may be several files.
 */
final class PortalRegistrationDocuments
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public const MAX_BANK_FILES = 5;

    /**
     * @var array<string, array{label: string, extensions: list<string>, multiple: bool}>
     */
    private const KINDS = [
        'photo' => ['label' => 'The profile photo', 'extensions' => ['jpg', 'jpeg', 'png'], 'multiple' => false],
        'tin' => ['label' => 'The TIN ID', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => false],
        'sss' => ['label' => 'The SSS ID', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => false],
        'philhealth' => ['label' => 'The PhilHealth ID', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => false],
        'hdmf' => ['label' => 'The HDMF ID', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => false],
        // Optional (2026-10-12): never in REQUIRED, so a submit goes through without it.
        'national_id' => ['label' => 'The national ID', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => false],
        'bank' => ['label' => 'The bank certificate', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png'], 'multiple' => true],
    ];

    /** The kinds a submit must have at least one of. */
    public const REQUIRED = ['photo', 'tin', 'sss', 'philhealth', 'hdmf', 'bank'];

    /**
     * Stages one document on the registration. A single-file kind replaces what was there; the bank
     * certificate adds up to its cap.
     *
     * @return array{id: string, kind: string, name: string, mimeType: string, sizeBytes: int, uploadedAt: string}
     */
    public function store(object $registration, Request $request): array
    {
        $kind = strtolower(trim((string) $request->input('kind', '')));
        if (! array_key_exists($kind, self::KINDS)) {
            abort(422, 'Choose which document the file is.');
        }
        $file = $this->validatedFile($request);
        $valid = PortalPrivateFiles::validated($file);
        $extension = strtolower(pathinfo($valid['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, self::KINDS[$kind]['extensions'], true)) {
            $allowed = array_map(static fn (string $value): string => strtoupper($value), self::KINDS[$kind]['extensions']);
            abort(422, self::KINDS[$kind]['label'].' is a '.implode(' or ', $allowed).' file.');
        }

        $registrationId = (int) $registration->id;
        $existing = $this->table()->where('registration_id', $registrationId)->where('kind', $kind)->orderBy('id')->get(['id', 'path']);
        if (self::KINDS[$kind]['multiple']) {
            if ($existing->count() >= self::MAX_BANK_FILES) {
                abort(422, 'Up to '.self::MAX_BANK_FILES.' bank certificate files; remove one before adding another.');
            }
        } else {
            // One file per kind: the new one is what the candidate meant to upload.
            foreach ($existing as $row) {
                PortalPrivateFiles::delete((string) $row->path);
            }
            $this->table()->where('registration_id', $registrationId)->where('kind', $kind)->delete();
        }

        $path = PortalPrivateFiles::store('registrations/'.$registrationId, $file, (string) Str::uuid());
        $id = (int) $this->table()->insertGetId([
            'registration_id' => $registrationId,
            'kind' => $kind,
            'name' => $valid['name'],
            'mime' => $valid['mime'],
            'size_bytes' => $valid['size'],
            'path' => $path,
            'uploaded_at' => now(),
        ]);

        return $this->present((object) [
            'id' => $id,
            'kind' => $kind,
            'name' => $valid['name'],
            'mime' => $valid['mime'],
            'size_bytes' => $valid['size'],
            'uploaded_at' => now()->toDateTimeString(),
        ]);
    }

    public function destroy(int $registrationId, int $documentId): void
    {
        $row = $this->table()->where('registration_id', $registrationId)->where('id', $documentId)->first(['id', 'path']);
        if ($row === null) {
            abort(404, 'That document was not found on this registration.');
        }

        $this->table()->where('id', $row->id)->delete();
        PortalPrivateFiles::delete((string) $row->path);
    }

    /**
     * @return list<array{id: string, kind: string, name: string, mimeType: string, sizeBytes: int, uploadedAt: string}>
     */
    public function listFor(int $registrationId): array
    {
        return $this->table()
            ->where('registration_id', $registrationId)
            ->orderBy('kind')
            ->orderBy('id')
            ->get(['id', 'kind', 'name', 'mime', 'size_bytes', 'uploaded_at'])
            ->map(fn (object $row): array => $this->present($row))
            ->all();
    }

    /**
     * @return array{name: string, mime: string, path: string}
     */
    public function rowFor(int $registrationId, int $documentId): array
    {
        $row = $this->table()->where('registration_id', $registrationId)->where('id', $documentId)->first(['name', 'mime', 'path']);
        if ($row === null) {
            abort(404, 'That document was not found on this registration.');
        }
        if (! PortalPrivateFiles::exists((string) $row->path)) {
            abort(404, 'That document is no longer kept on the server.');
        }

        return ['name' => (string) $row->name, 'mime' => (string) $row->mime, 'path' => (string) $row->path];
    }

    /**
     * @return list<string> The required kinds with nothing uploaded yet.
     */
    public function missingRequired(int $registrationId): array
    {
        $present = $this->table()->where('registration_id', $registrationId)->pluck('kind')->all();

        return array_values(array_diff(self::REQUIRED, $present));
    }

    /**
     * The one file a request carries. A body past post_max_size reaches PHP empty, and a file past
     * upload_max_filesize arrives as an error, so both say what this server takes.
     */
    private function validatedFile(Request $request): UploadedFile
    {
        $file = $request->file('file');
        if ($file === null) {
            $length = (int) $request->server('CONTENT_LENGTH', 0);
            if ($length > 0 && $length > PortalPrivateFiles::iniBytes((string) ini_get('post_max_size'))) {
                abort(413, PortalPrivateFiles::serverLimitMessage());
            }
            abort(422, 'Choose a file to upload.');
        }
        if (! $file instanceof UploadedFile) {
            abort(422, 'Upload one file at a time.');
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
        if ($size > self::MAX_FILE_BYTES) {
            abort(422, 'A document can be up to '.PortalPrivateFiles::megabytes(self::MAX_FILE_BYTES).'. Choose a smaller one.');
        }

        return $file;
    }

    /**
     * @return array{id: string, kind: string, name: string, mimeType: string, sizeBytes: int, uploadedAt: string}
     */
    private function present(object $row): array
    {
        return [
            'id' => (string) (int) $row->id,
            'kind' => (string) $row->kind,
            'name' => (string) $row->name,
            'mimeType' => (string) $row->mime,
            'sizeBytes' => (int) $row->size_bytes,
            'uploadedAt' => (string) $row->uploaded_at,
        ];
    }

    private function table(): Builder
    {
        return $this->connection()->table('register_documents');
    }

    private function connection(): Connection
    {
        return Employee::query()->getConnection();
    }
}
