<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\Reimbursement;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Requests / Reimbursement: the signed-in member's own expense claims.
 *
 * SQL stores one row per item. The form submits several items as one application, so this class
 * writes them in one transaction under the same created stamp and reads them back grouped that
 * way. `team` is the office the expense belongs to -- Angeles Pampanga Office, Cebu Office -- not
 * a department. Any calendar date is allowed: a reimbursement records spending, it does not book
 * a day the way leave does.
 */
final class PortalReimbursementRequests
{
    public const CACHE_TTL_SECONDS = 30;

    // Reimbursement records spending rather than booking a day, so the window is wide: ten years
    // back, a year ahead. A tighter clamp would refuse a receipt that is still sitting on a desk.
    private const MAX_RANGE_MONTHS = 120;

    private const MAX_AHEAD_MONTHS = 12;

    private const MAX_ITEMS = 20;

    private const MAX_LABEL = 500;

    private const MAX_PURPOSE = 500;

    private const MAX_COST = 999999999.99;

    private const MAX_QUANTITY = 9999;

    private const MAX_RECEIPT_BYTES = 8388608;

    private const RECEIPT_FIELD = 'receipts';

    private const RECEIPT_TABLE = 'reimbursements';

    private const RECEIPT_CACHE_TTL_SECONDS = 3600;

    /** @var list<string> */
    private const RECEIPT_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];

    private const NEW_STATUS = 'Pending';

    private const CANCELLED_STATUS = 'Cancelled';

    private const CACHE_VERSION_KEY = 'portal:requests:reimbursement:version';

    private const CORE_TARGET = 'portal.requests';

    private const CORE_RESOURCE = 'requests.reimbursement';

    /**
     * The offices the old portal offered. Filing is allow-listed against this pair; a historical
     * row may still carry an older `team` value, but the picker does not.
     *
     * @var list<string>
     */
    private const OFFICES = [
        'Angeles Pampanga Office',
        'Cebu Office',
    ];

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalTimezone $timezone,
        private readonly PortalCloudinary $cloudinary,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     teams: list<string>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:requests:reimbursement:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            return [
                'section' => 'requests',
                'resource' => 'reimbursement',
                'data' => $this->ownClaims($actor, $from, $to),
                'teams' => $this->offices(),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * File one claim. Every item lands in one transaction under the same created stamp, so a
     * three-item application cannot save as two items and a failure.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $date = $this->validatedDate($request);
        $items = $this->validatedItems($actor, $request);
        $employeeId = (int) $actor->getKey();
        $name = $this->memberName($actor);
        $createdAt = $this->timezone->now();

        $insertedIds = $this->connection()->transaction(function () use (
            $date,
            $items,
            $employeeId,
            $name,
            $createdAt,
        ): array {
            $ids = [];
            foreach ($items as $item) {
                $id = (int) $this->connection()->table('reimbursements')->insertGetId([
                    'reimb_date' => $date,
                    'item' => $item['label'],
                    'cost' => $item['cost'],
                    'qty' => $item['quantity'],
                    'purpose' => $item['purpose'],
                    'employee_name_input' => $name,
                    'team' => $item['teamLabel'],
                    'status' => self::NEW_STATUS,
                    'date_created' => $createdAt->toDateTimeString(),
                ]);
                $this->connection()->table('employees_reimbursements')->insert([
                    'employee_id' => $employeeId,
                    'reimbursement_id' => $id,
                ]);
                $this->writeReceipt($id, $item['receipt']);
                $ids[] = $id;
            }

            return $ids;
        });

        $claimId = (string) min($insertedIds);

        try {
            $this->ledger->recordAdd(
                CoreLedger::PRODUCT_PORTAL,
                CoreRecycleKey::reimbursementRequest($employeeId, $date, (int) $claimId),
                self::CORE_TARGET,
                [
                    'id' => $claimId,
                    'requestedFor' => $date,
                    'itemCount' => count($insertedIds),
                ],
                self::CORE_RESOURCE,
                $claimId,
                $employeeId,
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            $this->audit->record(
                $actor,
                PortalLogAction::INSERT,
                self::CORE_RESOURCE,
                PortalActivityCopy::filedReimbursement($date),
                $claimId,
                $request,
            );
        } catch (Throwable $error) {
            $this->deleteClaims($insertedIds);
            throw $error;
        }

        $this->bumpCache();
        $this->forgetReceipts($actor, $items);

        $claim = $this->readClaim($insertedIds, $actor);
        if ($claim === null) {
            abort(500, 'The claim was filed but could not be read back.');
        }

        return [
            'section' => 'requests',
            'resource' => 'reimbursement',
            'data' => $claim,
        ];
    }

    /**
     * Change a claim that nobody has decided on yet. Every item is rewritten in one transaction
     * under the stamp the filing already had, so the group stays one claim.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function replace(Employee $actor, string $id, Request $request): array
    {
        $rows = $this->ownPendingClaim($actor, $id);
        $date = $this->validatedDate($request);
        $items = $this->validatedItems($actor, $request);
        $employeeId = (int) $actor->getKey();
        $name = $this->memberName($actor);
        $seed = $rows[0];
        $createdAt = $this->createdStamp($seed->date_created ?? null);
        $existingIds = array_map(static fn (object $row): int => (int) $row->id, $rows);
        sort($existingIds);

        $keptIds = $this->connection()->transaction(function () use (
            $date,
            $items,
            $employeeId,
            $name,
            $createdAt,
            $existingIds,
        ): array {
            $kept = [];
            foreach ($items as $index => $item) {
                $payload = [
                    'reimb_date' => $date,
                    'item' => $item['label'],
                    'cost' => $item['cost'],
                    'qty' => $item['quantity'],
                    'purpose' => $item['purpose'],
                    'employee_name_input' => $name,
                    'team' => $item['teamLabel'],
                    'status' => self::NEW_STATUS,
                ];
                $existingId = $existingIds[$index] ?? null;
                if ($existingId !== null) {
                    $this->connection()->table('reimbursements')->where('id', $existingId)->update($payload);
                    $this->writeReceipt($existingId, $item['receipt']);
                    $kept[] = $existingId;

                    continue;
                }

                $payload['date_created'] = $createdAt;
                $newId = (int) $this->connection()->table('reimbursements')->insertGetId($payload);
                $this->connection()->table('employees_reimbursements')->insert([
                    'employee_id' => $employeeId,
                    'reimbursement_id' => $newId,
                ]);
                $this->writeReceipt($newId, $item['receipt']);
                $kept[] = $newId;
            }

            $this->deleteClaims(array_values(array_diff($existingIds, $kept)));

            return $kept;
        });

        $claimId = (string) min($keptIds);

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => $claimId,
                'requestedFor' => $date,
                'itemCount' => count($keptIds),
            ],
            self::CORE_RESOURCE,
            $claimId,
            CoreRecycleKey::reimbursementRequest($employeeId, $date, (int) $claimId),
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::updatedReimbursement($date),
            $claimId,
            $request,
        );

        $this->bumpCache();
        $this->forgetReceipts($actor, $items);

        $claim = $this->readClaim($keptIds, $actor);
        if ($claim === null) {
            abort(500, 'The claim was changed but could not be read back.');
        }

        return [
            'section' => 'requests',
            'resource' => 'reimbursement',
            'data' => $claim,
        ];
    }

    /**
     * Withdraw a claim. The rows stay and their status changes: the history is a record of what
     * was asked for, and a cancelled claim that vanished would read as one never filed.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function cancel(Employee $actor, string $id): array
    {
        $rows = $this->ownPendingClaim($actor, $id);
        $ids = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $date = $this->calendarDate($rows[0]->reimb_date ?? null) ?? '';
        $employeeId = (int) $actor->getKey();
        $claimId = (string) min($ids);

        $this->connection()->table('reimbursements')
            ->whereIn('id', $ids)
            ->update(['status' => self::CANCELLED_STATUS]);

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => $claimId,
                'requestedFor' => $date,
                'status' => self::CANCELLED_STATUS,
            ],
            self::CORE_RESOURCE,
            $claimId,
            CoreRecycleKey::reimbursementRequest($employeeId, $date, (int) $claimId),
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::cancelledReimbursement($date),
            $claimId,
        );

        $this->bumpCache();

        $claim = $this->readClaim($ids, $actor);
        if ($claim === null) {
            abort(500, 'The claim was cancelled but could not be read back.');
        }

        return [
            'section' => 'requests',
            'resource' => 'reimbursement',
            'data' => $claim,
        ];
    }

    /**
     * Upload one receipt to Cloudinary and hold it until the claim is filed. The file is not a
     * row yet: the item id does not exist until POST /requests/reimbursement writes it.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function storeReceipt(Employee $actor, Request $request): array
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'Choose an image or PDF receipt.');
        }
        if ($file->getSize() > self::MAX_RECEIPT_BYTES) {
            abort(422, 'A receipt cannot be larger than 8 MB.');
        }
        $mime = $file->getMimeType();
        if (! is_string($mime) || ! in_array($mime, self::RECEIPT_MIMES, true)) {
            abort(422, 'A receipt has to be a JPEG, PNG, WebP, GIF or PDF.');
        }

        $stored = $this->cloudinary->uploadReceipt($file);
        $receiptId = (string) Str::uuid();
        Cache::put(
            $this->receiptCacheKey($actor, $receiptId),
            $stored,
            self::RECEIPT_CACHE_TTL_SECONDS,
        );

        return [
            'section' => 'requests',
            'resource' => 'reimbursement',
            'data' => [
                'receiptId' => $receiptId,
                'fileName' => $stored['fileName'],
                'fileUrl' => $stored['fileUrl'],
                'mimeType' => $stored['mimeType'],
                'sizeBytes' => $stored['sizeBytes'],
                'thumbUrl' => $this->cloudinary->thumbUrl($stored['fileUrl'], $stored['mimeType']),
            ],
        ];
    }

    /**
     * The member's own claim, still undecided. Somebody else's is a 404 rather than a 403:
     * whose claim it is is not this member's to learn. One SQL row is one item, so this returns
     * every sibling that grouped with the requested id.
     *
     * @return list<object>
     */
    private function ownPendingClaim(Employee $actor, string $id): array
    {
        if (preg_match('/^\d{1,11}$/', trim($id)) !== 1) {
            abort(404, 'That request was not found.');
        }

        $employeeId = (int) $actor->getKey();
        $seed = $this->connection()
            ->table('reimbursements')
            ->join(
                'employees_reimbursements',
                'employees_reimbursements.reimbursement_id',
                '=',
                'reimbursements.id',
            )
            ->select([
                'reimbursements.id',
                'reimbursements.reimb_date',
                'reimbursements.status',
                'reimbursements.date_created',
            ])
            ->where('employees_reimbursements.employee_id', $employeeId)
            ->where('reimbursements.id', (int) $id)
            ->first();

        if ($seed === null) {
            abort(404, 'That request was not found.');
        }

        if ($this->claimStatus($seed->status ?? null) !== 'pending') {
            abort(422, 'Only a request nobody has decided on yet can be changed.');
        }

        $day = $this->calendarDate($seed->reimb_date ?? null);
        $key = $this->claimKey($employeeId, $seed);
        $candidates = $this->connection()
            ->table('reimbursements')
            ->join(
                'employees_reimbursements',
                'employees_reimbursements.reimbursement_id',
                '=',
                'reimbursements.id',
            )
            ->select([
                'reimbursements.id',
                'reimbursements.reimb_date',
                'reimbursements.status',
                'reimbursements.date_created',
            ])
            ->where('employees_reimbursements.employee_id', $employeeId)
            ->where('reimbursements.status', $seed->status)
            ->when($day !== null, fn ($query) => $query->whereDate('reimbursements.reimb_date', $day))
            ->orderBy('reimbursements.id')
            ->get();

        $rows = [];
        foreach ($candidates as $row) {
            if ($this->claimKey($employeeId, $row) === $key) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            abort(404, 'That request was not found.');
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownClaims(Employee $actor, string $from, string $to): array
    {
        $employeeId = (int) $actor->getKey();
        $rows = $this->connection()
            ->table('reimbursements')
            ->join(
                'employees_reimbursements',
                'employees_reimbursements.reimbursement_id',
                '=',
                'reimbursements.id',
            )
            ->select([
                'reimbursements.id',
                'reimbursements.reimb_date',
                'reimbursements.item',
                'reimbursements.cost',
                'reimbursements.qty',
                'reimbursements.purpose',
                'reimbursements.team',
                'reimbursements.status',
                'reimbursements.approver_remarks',
                'reimbursements.date_created',
                'reimbursements.employee_name_input',
            ])
            ->where('employees_reimbursements.employee_id', $employeeId)
            ->whereNotNull('reimbursements.reimb_date')
            ->whereBetween('reimbursements.reimb_date', [$from, $to])
            ->orderByDesc('reimbursements.reimb_date')
            ->orderByDesc('reimbursements.id')
            ->get();

        $receipts = $this->receiptsByItemId(
            $rows->map(static fn (object $row): int => (int) $row->id)->all(),
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[$this->claimKey($employeeId, $row)][] = $row;
        }

        $data = [];
        foreach ($groups as $group) {
            $claim = $this->presentClaim($group, $actor, $receipts);
            if ($claim !== null) {
                $data[] = $claim;
            }
        }

        return $data;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>|null
     */
    private function readClaim(array $ids, Employee $actor): ?array
    {
        if ($ids === []) {
            return null;
        }

        $rows = $this->connection()
            ->table('reimbursements')
            ->select([
                'id',
                'reimb_date',
                'item',
                'cost',
                'qty',
                'purpose',
                'team',
                'status',
                'approver_remarks',
                'date_created',
                'employee_name_input',
            ])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->presentClaim($rows, $actor, $this->receiptsByItemId($ids));
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int, object>  $receipts
     * @return array<string, mixed>|null
     */
    private function presentClaim(array $rows, Employee $actor, array $receipts): ?array
    {
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $submittedOn = $this->calendarDate($first->reimb_date ?? null);
        if ($submittedOn === null) {
            return null;
        }

        $ids = [];
        $items = [];
        $approverRemarks = null;
        foreach ($rows as $row) {
            $itemId = (int) $row->id;
            $ids[] = $itemId;
            $receipt = $receipts[$itemId] ?? null;
            $fileUrl = $receipt === null ? null : $this->text($receipt->file_url ?? null);
            $fileName = $receipt === null ? null : $this->text($receipt->file_name ?? null);
            $mime = $receipt === null ? null : $this->text($receipt->mime_type ?? null);
            $items[] = [
                'id' => (string) $itemId,
                'label' => $this->text($row->item ?? null) ?? 'Item',
                'cost' => round((float) ($row->cost ?? 0), 2),
                'quantity' => $this->quantity($row->qty ?? null),
                'teamLabel' => $this->text($row->team ?? null),
                'purpose' => $this->text($row->purpose ?? null),
                'receiptName' => $fileName,
                'receiptUrl' => $fileUrl,
                'receiptMime' => $mime,
                'receiptThumbUrl' => $fileUrl === null || $mime === null
                    ? null
                    : $this->cloudinary->thumbUrl($fileUrl, $mime),
            ];
            if ($approverRemarks === null) {
                $approverRemarks = $this->text($row->approver_remarks ?? null);
            }
        }

        $name = $this->composedName($actor)
            ?? $this->text($first->employee_name_input ?? null)
            ?? 'Member';

        return [
            'id' => (string) min($ids),
            'referenceCode' => PortalSubmittedReportPresenter::referenceCode(
                is_string($actor->id_no) ? $actor->id_no : null,
                (int) $actor->getKey(),
            ),
            'memberName' => $name,
            'submittedOn' => $submittedOn,
            'status' => $this->claimStatus($first->status ?? null),
            'approverRemarks' => $approverRemarks,
            'items' => $items,
        ];
    }

    /**
     * Rows that share a created stamp and a status were one application. Seeded Airtable rows
     * only carry a date, so same-day items still group; a new filing writes a datetime so two
     * submissions a minute apart stay two claims.
     */
    private function claimKey(int $employeeId, object $row): string
    {
        return $employeeId.'|'.$this->createdStamp($row->date_created ?? null).'|'.strtolower(trim((string) ($row->status ?? '')));
    }

    private function createdStamp(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }
        $text = trim((string) ($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $text) === 1) {
            return str_replace('T', ' ', substr($text, 0, 19));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
            return substr($text, 0, 10);
        }

        return $text;
    }

    /**
     * Completed is how the seeded claims mark a paid reimbursement. The rest of the portal
     * speaks approved / pending / rejected / cancelled, so that is what the payload sends.
     */
    private function claimStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));
        if (in_array($value, ['completed', 'complete', 'paid', 'done'], true)) {
            return 'approved';
        }

        return PortalSubmittedReportPresenter::requestStatus($status);
    }

    /**
     * @return list<string>
     */
    private function offices(): array
    {
        return self::OFFICES;
    }

    /**
     * Reimbursement records spending, so any real calendar date is allowed -- past, today, or
     * ahead. The range clamp is only so a typo cannot store year 0001.
     */
    private function validatedDate(Request $request): string
    {
        $date = $this->parseDate(trim((string) $request->input('requestDate', '')));
        $today = $this->timezone->today();
        if ($date->lt($today->copy()->subMonths(self::MAX_RANGE_MONTHS))) {
            abort(422, 'That date is more than ten years ago.');
        }
        if ($date->gt($today->copy()->addMonths(self::MAX_AHEAD_MONTHS))) {
            abort(422, 'That date is more than a year ahead.');
        }

        return $date->toDateString();
    }

    /**
     * @return list<array{
     *     label: string,
     *     cost: float,
     *     quantity: int,
     *     teamLabel: string,
     *     purpose: ?string,
     *     receipt: ?array{fileName: string, fileUrl: string, sizeBytes: int, mimeType: string, cacheKey: ?string}
     * }>
     */
    private function validatedItems(Employee $actor, Request $request): array
    {
        $raw = $request->input('items');
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Add at least one item before filing.');
        }
        if (count($raw) > self::MAX_ITEMS) {
            abort(422, 'A reimbursement cannot hold more than 20 items.');
        }

        $offices = $this->offices();
        $items = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                abort(422, 'Each item must be an object.');
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                abort(422, 'Every reimbursement needs an item description.');
            }
            if (strlen($label) > self::MAX_LABEL) {
                abort(422, 'The item description cannot exceed 500 characters.');
            }
            $team = trim((string) ($item['teamLabel'] ?? ''));
            if (! in_array($team, $offices, true)) {
                abort(422, 'Choose one of the offices the form offers.');
            }
            $items[] = [
                'label' => $label,
                'cost' => $this->validatedCost($item['cost'] ?? null),
                'quantity' => $this->validatedQuantity($item['quantity'] ?? null),
                'teamLabel' => $team,
                'purpose' => $this->validatedPurpose($item['purpose'] ?? null),
                'receipt' => $this->validatedReceipt($actor, $item),
            ];
        }

        return $items;
    }

    private function validatedCost(mixed $value): float
    {
        if (! is_numeric($value)) {
            abort(422, 'Every reimbursement needs a cost above zero.');
        }
        $cost = round((float) $value, 2);
        if ($cost <= 0 || $cost > self::MAX_COST) {
            abort(422, 'Every reimbursement needs a cost above zero.');
        }

        return $cost;
    }

    private function validatedQuantity(mixed $value): int
    {
        if (! is_numeric($value)) {
            abort(422, 'Quantity has to be a whole number above zero.');
        }
        $quantity = (int) $value;
        if ((float) $value !== (float) $quantity || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
            abort(422, 'Quantity has to be a whole number above zero.');
        }

        return $quantity;
    }

    private function validatedPurpose(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            abort(422, 'Purpose must be text.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > self::MAX_PURPOSE) {
            abort(422, 'The purpose cannot exceed 500 characters.');
        }

        return $text;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $to = $toRaw === ''
            ? $this->timezone->today()->addMonths(self::MAX_AHEAD_MONTHS)
            : $this->parseDate($toRaw);
        $floor = $to->copy()->subMonths(self::MAX_RANGE_MONTHS + self::MAX_AHEAD_MONTHS)->startOfMonth();
        $from = $fromRaw === '' ? $floor->copy() : $this->parseDate($fromRaw);

        if ($from->gt($to)) {
            abort(422, 'The from date must be on or before the to date.');
        }
        if ($from->lt($floor)) {
            $from = $floor;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->startOfDay();
    }

    private function calendarDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function quantity(mixed $value): int
    {
        $quantity = (int) round((float) ($value ?? 1));

        return $quantity > 0 ? $quantity : 1;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function composedName(Employee $actor): ?string
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );

        return $name === 'Member' ? null : $name;
    }

    private function memberName(Employee $actor): string
    {
        $name = $this->composedName($actor);
        if ($name === null) {
            abort(422, 'Your profile has no name on it, so a request cannot be filed under it.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{fileName: string, fileUrl: string, sizeBytes: int, mimeType: string, cacheKey: ?string}|null
     */
    private function validatedReceipt(Employee $actor, array $item): ?array
    {
        $receiptId = trim((string) ($item['receiptId'] ?? ''));
        if ($receiptId !== '') {
            $key = $this->receiptCacheKey($actor, $receiptId);
            $cached = Cache::get($key);
            if (! is_array($cached) || ! is_string($cached['fileUrl'] ?? null)) {
                abort(422, 'That receipt upload expired. Attach the file again.');
            }

            return [
                'fileName' => is_string($cached['fileName'] ?? null) ? $cached['fileName'] : 'receipt',
                'fileUrl' => $cached['fileUrl'],
                'sizeBytes' => (int) ($cached['sizeBytes'] ?? 0),
                'mimeType' => is_string($cached['mimeType'] ?? null)
                    ? $cached['mimeType']
                    : 'application/octet-stream',
                'cacheKey' => $key,
            ];
        }

        $url = trim((string) ($item['receiptUrl'] ?? ''));
        if ($url === '') {
            return null;
        }
        if (! $this->cloudinary->isOwnedUrl($url)) {
            abort(422, 'That receipt is not one this form uploaded.');
        }
        $name = trim((string) ($item['receiptName'] ?? ''));
        $mime = trim((string) ($item['receiptMime'] ?? ''));

        return [
            'fileName' => $name !== '' ? $name : 'receipt',
            'fileUrl' => $url,
            'sizeBytes' => is_numeric($item['receiptSize'] ?? null) ? (int) $item['receiptSize'] : 0,
            'mimeType' => $mime !== '' ? $mime : 'application/octet-stream',
            'cacheKey' => null,
        ];
    }

    /**
     * @param  array{fileName: string, fileUrl: string, sizeBytes: int, mimeType: string, cacheKey: ?string}|null  $receipt
     */
    private function writeReceipt(int $itemId, ?array $receipt): void
    {
        $this->connection()->table('attachments')
            ->where('table_name', self::RECEIPT_TABLE)
            ->where('record_id', $itemId)
            ->where('field_name', self::RECEIPT_FIELD)
            ->delete();

        if ($receipt === null) {
            return;
        }

        $this->connection()->table('attachments')->insert([
            'table_name' => self::RECEIPT_TABLE,
            'record_id' => $itemId,
            'field_name' => self::RECEIPT_FIELD,
            'file_url' => $receipt['fileUrl'],
            'file_name' => $receipt['fileName'],
            'file_size_bytes' => $receipt['sizeBytes'],
            'mime_type' => $receipt['mimeType'],
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function receiptsByItemId(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('attachments')
            ->select(['record_id', 'file_url', 'file_name', 'file_size_bytes', 'mime_type'])
            ->where('table_name', self::RECEIPT_TABLE)
            ->where('field_name', self::RECEIPT_FIELD)
            ->whereIn('record_id', $ids)
            ->get();

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int) $row->record_id] = $row;
        }

        return $mapped;
    }

    /**
     * @param  list<array{receipt: ?array{cacheKey: ?string}}>  $items
     */
    private function forgetReceipts(Employee $actor, array $items): void
    {
        foreach ($items as $item) {
            $key = $item['receipt']['cacheKey'] ?? null;
            if (is_string($key) && $key !== '') {
                Cache::forget($key);
            }
        }
    }

    private function receiptCacheKey(Employee $actor, string $receiptId): string
    {
        return 'portal:requests:reimbursement:receipt:'.$actor->getKey().':'.$receiptId;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteClaims(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection()->table('attachments')
            ->where('table_name', self::RECEIPT_TABLE)
            ->where('field_name', self::RECEIPT_FIELD)
            ->whereIn('record_id', $ids)
            ->delete();
        $this->connection()->table('employees_reimbursements')->whereIn('reimbursement_id', $ids)->delete();
        $this->connection()->table('reimbursements')->whereIn('id', $ids)->delete();
    }

    private function connection(): Connection
    {
        return Reimbursement::query()->getConnection();
    }
}
