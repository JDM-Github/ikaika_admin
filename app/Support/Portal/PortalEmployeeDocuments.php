<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
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
 */
final class PortalEmployeeDocuments
{
    /** The employees attachment field each registration document kind fills, as Airtable names them. */
    public const FIELD_FOR_KIND = [
        'photo' => 'photo',
        'tin' => 'tax_identification',
        'sss' => 'ss_id',
        'philhealth' => 'philhealth_id',
        'hdmf' => 'hdmf_id',
        'national_id' => 'national_id',
        'bank' => 'bank_certificate',
    ];

    private const FOLDER = 'ikaika-portal/employees';

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
