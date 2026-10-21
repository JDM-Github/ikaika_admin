<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Profile / Payment QR codes (2026-10-21, user): a member's bank or e-wallet QRs, one per bank. Filing a
 * reimbursement names one of them, and PortalReimbursementRequests copies it onto the claim for the admin
 * who pays it to scan.
 */
final class PortalPaymentQrs
{
    public const FOLDER = 'ikaika-portal/payment-qrs';

    public const MAX_QRS = 10;

    public const MAX_BYTES = 5242880;

    private const MAX_BANK_LABEL = 120;

    // A QR has to be a picture the browser can show to be scanned: no PDF, no HEIC.
    /** @var list<string> */
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly PortalCloudinary $cloudinary,
        private readonly PortalTimezone $timezone,
    ) {}

    /**
     * The newest first, so the one a member added or replaced last is the one the form offers first.
     *
     * @return list<array{id: string, bankLabel: string, fileName: string, fileUrl: string, mimeType: ?string}>
     */
    public function mine(Employee $actor): array
    {
        return $this->connection()->table('employee_payment_qrs')
            ->where('employee_id', (int) $actor->getKey())
            ->orderByDesc('date_updated')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => $this->present($row))
            ->values()
            ->all();
    }

    /**
     * Adds a QR, or replaces the one already kept for that bank.
     *
     * @return array{id: string, bankLabel: string, fileName: string, fileUrl: string, mimeType: ?string}
     */
    public function store(Employee $actor, Request $request): array
    {
        $bank = trim((string) $request->input('bankLabel', ''));
        if ($bank === '') {
            abort(422, 'Choose the bank or e-wallet this QR is for.');
        }
        if (mb_strlen($bank) > self::MAX_BANK_LABEL) {
            abort(422, 'The bank name cannot exceed 120 characters.');
        }
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'Choose a picture of your QR code.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            abort(422, 'A payment QR cannot be larger than 5 MB.');
        }
        $mime = $file->getMimeType();
        if (! is_string($mime) || ! in_array($mime, self::MIMES, true)) {
            abort(422, 'A payment QR has to be a picture: JPEG, PNG, WebP or GIF.');
        }

        $employeeId = (int) $actor->getKey();
        $existing = $this->connection()->table('employee_payment_qrs')
            ->where('employee_id', $employeeId)
            ->where('bank_label', $bank)
            ->first();
        if ($existing === null && $this->connection()->table('employee_payment_qrs')->where('employee_id', $employeeId)->count() >= self::MAX_QRS) {
            abort(422, 'You can keep up to 10 payment QR codes. Remove one first.');
        }

        $stored = $this->cloudinary->uploadReceipt($file, self::FOLDER);
        $fields = [
            'file_url' => $stored['fileUrl'],
            'file_name' => $stored['fileName'],
            'mime_type' => $stored['mimeType'],
            'file_size_bytes' => $stored['sizeBytes'],
            'date_updated' => $this->timezone->now()->toDateTimeString(),
        ];
        if ($existing !== null) {
            $id = (int) $existing->id;
            $this->connection()->table('employee_payment_qrs')->where('id', $id)->update($fields);
        } else {
            $id = (int) $this->connection()->table('employee_payment_qrs')->insertGetId([
                'employee_id' => $employeeId,
                'bank_label' => $bank,
                ...$fields,
            ]);
        }

        $row = $this->connection()->table('employee_payment_qrs')->where('id', $id)->first();
        if ($row === null) {
            abort(500, 'The payment QR was saved but could not be read back.');
        }

        return $this->present($row);
    }

    /**
     * @return array{id: string, bankLabel: string, fileName: string, fileUrl: string, mimeType: ?string}
     */
    public function remove(Employee $actor, string $id): array
    {
        $row = $this->ownRow($actor, $id);
        if ($row === null) {
            abort(404, 'That payment QR was not found.');
        }
        $this->connection()->table('employee_payment_qrs')->where('id', (int) $row->id)->delete();

        return $this->present($row);
    }

    /**
     * The member's own QR for a claim to copy; null when it is not theirs or has been removed.
     *
     * @return array{bankLabel: string, fileUrl: string, sizeBytes: int, mimeType: string}|null
     */
    public function own(Employee $actor, string $id): ?array
    {
        $row = $this->ownRow($actor, $id);
        if ($row === null) {
            return null;
        }

        return [
            'bankLabel' => (string) $row->bank_label,
            'fileUrl' => (string) $row->file_url,
            'sizeBytes' => (int) ($row->file_size_bytes ?? 0),
            'mimeType' => is_string($row->mime_type ?? null) ? $row->mime_type : 'image/png',
        ];
    }

    private function ownRow(Employee $actor, string $id): ?object
    {
        if (preg_match('/^\d{1,11}$/', trim($id)) !== 1) {
            return null;
        }

        return $this->connection()->table('employee_payment_qrs')
            ->where('id', (int) $id)
            ->where('employee_id', (int) $actor->getKey())
            ->first();
    }

    /**
     * @return array{id: string, bankLabel: string, fileName: string, fileUrl: string, mimeType: ?string}
     */
    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'bankLabel' => (string) $row->bank_label,
            'fileName' => (string) $row->file_name,
            'fileUrl' => (string) $row->file_url,
            'mimeType' => is_string($row->mime_type ?? null) ? $row->mime_type : null,
        ];
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
