<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * An employee's documents (2026-10-12, user): their files on Cloudinary, kept in attachments under
 * the field Airtable gives each (photo, tax_identification, ss_id and the rest), as every employee's
 * are. Promotion copies the files a candidate sent at registration there, so a promoted employee's
 * documents and photo sit where everyone's do; the registration keeps its own private copy.
 *
 * Whoever may change someone's private details may change their documents too (2026-10-13, user):
 * a file put in a slot replaces what it held -- a bank certificate joins the others, up to five --
 * and any of their files can be taken off. A signature is a slot of its own, a picture like the photo.
 */
final class PortalEmployeeDocuments
{
    /** The employees attachment field each registration document kind fills, as Airtable names them. */
    public const FIELD_FOR_KIND = [
        'photo' => 'photo',
        'signature' => 'signature',
        'tin' => 'tax_identification',
        'sss' => 'ss_id',
        'philhealth' => 'philhealth_id',
        'hdmf' => 'hdmf_id',
        'national_id' => 'national_id',
        'bank' => 'bank_certificate',
    ];

    private const FOLDER = 'ikaika-portal/employees';

    private const PICTURES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const PICTURES_OR_PDF = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'application/pdf'];

    // The portal's receipt picker refuses past this too.
    private const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    /**
     * The slots a file may be put in, what each takes, and whether it holds more than one.
     *
     * @var array<string, array{label: string, mimes: list<string>, multiple: bool}>
     */
    public const EDITABLE = [
        'photo' => ['label' => 'The profile photo', 'mimes' => self::PICTURES, 'multiple' => false],
        'signature' => ['label' => 'The signature', 'mimes' => self::PICTURES, 'multiple' => false],
        'tax_identification' => ['label' => 'The TIN ID', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => false],
        'ss_id' => ['label' => 'The SSS ID', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => false],
        'philhealth_id' => ['label' => 'The PhilHealth ID', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => false],
        'hdmf_id' => ['label' => 'The Pag-IBIG (HDMF) ID', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => false],
        'national_id' => ['label' => 'The national ID', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => false],
        'bank_certificate' => ['label' => 'A bank certificate', 'mimes' => self::PICTURES_OR_PDF, 'multiple' => true],
    ];

    public function __construct(
        private readonly PortalRegistrationDocuments $documents,
        private readonly PortalCloudinary $cloudinary,
    ) {}

    /**
     * Their files on Cloudinary, newest first within each field; only an https link is listed.
     *
     * @return list<array{id: string, field: string, name: string, mimeType: string, sizeBytes: int, url: string, thumbUrl: string|null}>
     */
    public function listFor(Employee $employee): array
    {
        return DB::connection('portal')->table('attachments')
            ->where('table_name', 'employees')
            ->where('record_id', (int) $employee->getKey())
            ->orderBy('field_name')
            ->orderByDesc('id')
            ->get(['id', 'field_name', 'file_url', 'file_name', 'file_size_bytes', 'mime_type'])
            ->filter(static fn (object $row): bool => is_string($row->file_url) && str_starts_with($row->file_url, 'https://'))
            ->map(fn (object $row): array => [
                'id' => (string) (int) $row->id,
                'field' => (string) $row->field_name,
                'name' => trim((string) $row->file_name) !== '' ? (string) $row->file_name : (string) $row->field_name,
                'mimeType' => (string) ($row->mime_type ?? ''),
                'sizeBytes' => (int) ($row->file_size_bytes ?? 0),
                'url' => (string) $row->file_url,
                'thumbUrl' => $this->cloudinary->thumbUrl((string) $row->file_url, (string) ($row->mime_type ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Each file of the registration they were promoted from becomes one of their attachments on
     * Cloudinary. Best effort: a file that fails is logged and stays in the registration, and one
     * already copied is skipped, so running it again copies only what is missing.
     *
     * @return array{copied: int, skipped: int, failed: int}
     */
    public function copyFromRegistration(Employee $employee, int $registrationId): array
    {
        $outcome = ['copied' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->documents->listFor($registrationId) as $document) {
            $field = self::FIELD_FOR_KIND[$document['kind']] ?? $document['kind'];
            if ($this->hasCopy($employee, $field, $document['name'])) {
                $outcome['skipped']++;

                continue;
            }

            try {
                $file = $this->documents->rowFor($registrationId, (int) $document['id']);
                $upload = new UploadedFile(Storage::disk(PortalPrivateFiles::DISK)->path($file['path']), $file['name'], $file['mime'], null, true);
                $stored = $this->cloudinary->uploadReceipt($upload, self::FOLDER);
                DB::connection('portal')->table('attachments')->insert([
                    'table_name' => 'employees',
                    'record_id' => (int) $employee->getKey(),
                    'field_name' => $field,
                    'file_url' => $stored['fileUrl'],
                    'file_name' => $file['name'],
                    'file_size_bytes' => $stored['sizeBytes'],
                    'mime_type' => $file['mime'],
                ]);
                $outcome['copied']++;
            } catch (Throwable $error) {
                Log::warning('portal.employee_documents.not_copied', [
                    'employeeId' => (int) $employee->getKey(),
                    'documentId' => (int) $document['id'],
                    'reason' => $error->getMessage(),
                ]);
                $outcome['failed']++;
            }
        }
        if ($outcome['copied'] > 0) {
            PortalCache::bump();
        }

        return $outcome;
    }

    /**
     * Every promoted registration's files, for the people promoted before promotion copied them.
     *
     * @return array{copied: int, skipped: int, failed: int}
     */
    public function copyAllPromoted(): array
    {
        $total = ['copied' => 0, 'skipped' => 0, 'failed' => 0];
        $promoted = DB::connection('portal')->table('registrations')->whereNotNull('promoted_employee_id')->orderBy('id')->get(['id', 'promoted_employee_id']);
        foreach ($promoted as $registration) {
            $employee = Employee::query()->find((int) $registration->promoted_employee_id);
            if (! $employee instanceof Employee) {
                continue;
            }
            foreach ($this->copyFromRegistration($employee, (int) $registration->id) as $key => $count) {
                $total[$key] += $count;
            }
        }

        return $total;
    }

    // A file put in one of their slots: it replaces what the slot held, or joins the bank certificates.
    public function upload(Employee $employee, string $field, mixed $file): void
    {
        $slot = self::EDITABLE[$field] ?? abort(422, 'Choose which document this is.');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'Choose a file to upload.');
        }
        if ((int) $file->getSize() > self::MAX_UPLOAD_BYTES) {
            abort(422, 'A document cannot be larger than 8 MB.');
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, $slot['mimes'], true)) {
            abort(422, $slot['label'].($slot['mimes'] === self::PICTURES ? ' has to be a JPG, PNG, WebP or GIF picture.' : ' has to be a picture or a PDF.'));
        }
        $held = $this->rowsOf($employee)->where('field_name', $field)->pluck('id')->all();
        if ($slot['multiple'] && count($held) >= PortalRegistrationDocuments::MAX_BANK_FILES) {
            abort(422, 'Keep up to '.PortalRegistrationDocuments::MAX_BANK_FILES.' bank certificates. Remove one first.');
        }

        // Stored before the row changes, so a failed upload leaves the slot as it was.
        $stored = $this->cloudinary->uploadReceipt($file, self::FOLDER);
        DB::connection('portal')->transaction(function () use ($employee, $field, $file, $mime, $stored, $slot, $held): void {
            if (! $slot['multiple'] && $held !== []) {
                $this->rowsOf($employee)->whereIn('id', $held)->delete();
            }
            DB::connection('portal')->table('attachments')->insert([
                'table_name' => 'employees',
                'record_id' => (int) $employee->getKey(),
                'field_name' => $field,
                'file_url' => $stored['fileUrl'],
                'file_name' => $file->getClientOriginalName(),
                'file_size_bytes' => $stored['sizeBytes'],
                'mime_type' => $mime,
            ]);
        });
        PortalCache::bump();
    }

    // One of their files taken off, and the slot it was in; another employee's is not found.
    public function remove(Employee $employee, int $documentId): string
    {
        $field = $this->rowsOf($employee)->where('id', $documentId)->value('field_name');
        if (! is_string($field)) {
            abort(404, 'That document was not found.');
        }
        $this->rowsOf($employee)->where('id', $documentId)->delete();
        PortalCache::bump();

        return $field;
    }

    private function rowsOf(Employee $employee): Builder
    {
        return DB::connection('portal')->table('attachments')
            ->where('table_name', 'employees')
            ->where('record_id', (int) $employee->getKey());
    }

    private function hasCopy(Employee $employee, string $field, string $name): bool
    {
        return DB::connection('portal')->table('attachments')
            ->where('table_name', 'employees')
            ->where('record_id', (int) $employee->getKey())
            ->where('field_name', $field)
            ->where('file_name', $name)
            ->exists();
    }
}
