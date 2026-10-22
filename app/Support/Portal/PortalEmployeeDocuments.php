<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
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
        private readonly CoreLedger $ledger,
    ) {}

    /**
     * Their files on Cloudinary, newest first within each field; only an https link is listed. A PDF
     * comes with its first page as its thumbnail and its pages as pictures, so it previews like a
     * picture does (2026-10-14, user).
     *
     * @return list<array{id: string, field: string, name: string, mimeType: string, sizeBytes: int, url: string, thumbUrl: string|null, pagesUrl: string|null}>
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
            ->map(fn (object $row): array => $this->described(
                (string) (int) $row->id,
                (string) $row->field_name,
                trim((string) $row->file_name) !== '' ? (string) $row->file_name : (string) $row->field_name,
                (string) ($row->mime_type ?? ''),
                (int) ($row->file_size_bytes ?? 0),
                (string) $row->file_url,
            ))
            ->values()
            ->all();
    }

    /**
     * A file as a document list shows it, kept or only asked for.
     *
     * @return array{id: string, field: string, name: string, mimeType: string, sizeBytes: int, url: string, thumbUrl: string|null, pagesUrl: string|null}
     */
    public function described(string $id, string $field, string $name, string $mime, int $sizeBytes, string $url): array
    {
        $isPdf = $mime === 'application/pdf';

        return [
            'id' => $id,
            'field' => $field,
            'name' => $name,
            'mimeType' => $mime,
            'sizeBytes' => $sizeBytes,
            'url' => $url,
            'thumbUrl' => $isPdf ? $this->cloudinary->pdfThumbUrl($url) : $this->cloudinary->thumbUrl($url, $mime),
            'pagesUrl' => $isPdf ? $this->cloudinary->pdfPagesUrl($url) : null,
        ];
    }

    /**
     * Each file of the registration they were promoted from becomes one of their attachments on
     * Cloudinary. Best effort: a file that fails is logged and stays in the registration, and one
     * already copied is skipped, so running it again copies only what is missing.
     *
     * @return array{copied: int, skipped: int, failed: int}
     */
    public function copyFromRegistration(Employee $employee, int $registrationId, ?Employee $actor = null): array
    {
        $outcome = ['copied' => 0, 'skipped' => 0, 'failed' => 0];
        $slots = [];
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
                $slots[] = $field;
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
        $this->ledgerChange($actor, $employee, $slots);

        return $outcome;
    }

    /**
     * One employees edit in the ledger naming the columns and document slots that changed, so the
     * Airtable sync sends exactly those (2026-10-20). Columns go as a plain list: the ledger strips
     * government and bank keys from every payload, and the sync reads the values from SQL anyway.
     *
     * @param  list<string>  $slots
     * @param  list<string>  $columns
     */
    public function ledgerChange(?Employee $actor, Employee $employee, array $slots, array $columns = []): void
    {
        if ($slots === [] && $columns === []) {
            return;
        }
        $parameters = ['table' => 'employees', 'key' => 'id', 'id' => (string) $employee->getKey(), 'columns' => array_values(array_unique($columns))];
        foreach (array_unique($slots) as $slot) {
            $parameters['doc_'.$slot] = true;
        }
        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            CoreLedger::PRODUCT_PORTAL.'.employees',
            $parameters,
            'employees',
            (string) $employee->getKey(),
            null,
            $actor === null ? null : (int) $actor->getKey(),
            $actor !== null && is_string($actor->id_no) ? $actor->id_no : null,
        );
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
        $this->checked($field, $file);
        $this->assertRoom($employee, [$field], []);
        // Stored before the row changes, so a failed upload leaves the slot as it was.
        $this->attach($employee, $this->stored($field, $file));
    }

    /**
     * A file checked for one of their slots and put on Cloudinary, not yet one of theirs: an upload
     * attaches it at once, a change someone asks for about themselves once it is approved (2026-10-14).
     *
     * @return array{field: string, fileUrl: string, fileName: string, sizeBytes: int, mimeType: string}
     */
    public function stored(string $field, mixed $file): array
    {
        $mime = $this->checked($field, $file);
        /** @var UploadedFile $file */
        $stored = $this->cloudinary->uploadReceipt($file, self::FOLDER);

        return ['field' => $field, 'fileUrl' => $stored['fileUrl'], 'fileName' => $file->getClientOriginalName(), 'sizeBytes' => $stored['sizeBytes'], 'mimeType' => $mime];
    }

    /**
     * A slot that holds several keeps to its limit once these files join it and those leave.
     *
     * @param  list<string>  $addedFields
     * @param  list<int>  $removedIds
     */
    public function assertRoom(Employee $employee, array $addedFields, array $removedIds): void
    {
        foreach (array_count_values($addedFields) as $field => $adding) {
            if (! (self::EDITABLE[$field]['multiple'] ?? false)) {
                continue;
            }
            $held = $this->rowsOf($employee)->where('field_name', $field)->whereNotIn('id', $removedIds)->count();
            if ($held + $adding > PortalRegistrationDocuments::MAX_BANK_FILES) {
                abort(422, 'Keep up to '.PortalRegistrationDocuments::MAX_BANK_FILES.' bank certificates. Remove one first.');
            }
        }
    }

    /**
     * A stored file made one of theirs: it replaces what its slot held, or joins the bank certificates.
     *
     * @param  array{field: string, fileUrl: string, fileName: string, sizeBytes: int, mimeType: string}  $stored
     */
    public function attach(Employee $employee, array $stored): void
    {
        $isMultiple = self::EDITABLE[$stored['field']]['multiple'] ?? false;
        DB::connection('portal')->transaction(function () use ($employee, $stored, $isMultiple): void {
            if (! $isMultiple) {
                $this->rowsOf($employee)->where('field_name', $stored['field'])->delete();
            }
            DB::connection('portal')->table('attachments')->insert([
                'table_name' => 'employees',
                'record_id' => (int) $employee->getKey(),
                'field_name' => $stored['field'],
                'file_url' => $stored['fileUrl'],
                'file_name' => $stored['fileName'],
                'file_size_bytes' => $stored['sizeBytes'],
                'mime_type' => $stored['mimeType'],
            ]);
        });
        PortalCache::bump();
    }

    // One of their files taken off, and the slot it was in; another employee's is not found.
    public function remove(Employee $employee, int $documentId): string
    {
        $field = $this->fieldOf($employee, $documentId);
        if ($field === null) {
            abort(404, 'That document was not found.');
        }
        $this->rowsOf($employee)->where('id', $documentId)->delete();
        PortalCache::bump();

        return $field;
    }

    // The slot one of their files is in; null when it is not one of theirs, or gone.
    public function fieldOf(Employee $employee, int $documentId): ?string
    {
        $field = $this->rowsOf($employee)->where('id', $documentId)->value('field_name');

        return is_string($field) ? $field : null;
    }

    // The file's type, once it suits the slot; a slot it does not suit, or no file, is refused.
    private function checked(string $field, mixed $file): string
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

        return $mime;
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
