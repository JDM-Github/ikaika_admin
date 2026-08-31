<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Recycle;
use Illuminate\Support\Carbon;

/**
 * Skinny Administration / Recycle Bin rows. List never returns the snapshot.
 * Show returns the stored generated report, never the restore lines.
 */
final class PortalRecycleBinPresenter
{
    public const TYPE_REPORT = 'report';

    public const TYPE_REQUEST = 'request';

    public const TYPE_PROJECT = 'project';
    /**
     * @return list<string>
     */
    public static function listColumns(): array
    {
        return [
            'id',
            'resource',
            'record_id',
            'payload',
            'deleted_by',
            'deleted_by_id_no',
            'purges_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    public static function restoreColumns(): array
    {
        return [
            'id',
            'product',
            'recycle_key',
            'database_target',
            'resource',
            'record_id',
            'payload',
            'deleted_by',
            'deleted_by_id_no',
            'purges_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    public static function employeeColumns(): array
    {
        return ['id', 'first_name', 'last_name', 'id_no'];
    }

    /**
     * @param  array<int, array{name: string, idNo: ?string}>  $employees
     * @return array{
     *     id: string,
     *     recordId: string,
     *     kind: string,
     *     submittedOn: ?string,
     *     title: string,
     *     resource: ?string,
     *     type: string,
     *     deletedAt: string,
     *     purgesAt: string,
     *     employeeId: ?int,
     *     employeeIdNo: ?string,
     *     employeeName: string
     * }
     */
    public static function item(Recycle $row, array $employees): array
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $kind = self::kind($payload['kind'] ?? null);
        $submittedOn = self::optionalDate($payload['submittedOn'] ?? null);
        $title = self::title($payload['title'] ?? null, $kind);
        $employeeId = is_numeric($row->deleted_by) ? (int) $row->deleted_by : null;
        $known = $employeeId !== null ? ($employees[$employeeId] ?? null) : null;
        $idNo = is_string($row->deleted_by_id_no) && $row->deleted_by_id_no !== ''
            ? $row->deleted_by_id_no
            : ($known['idNo'] ?? null);

        return [
            'id' => (string) $row->getKey(),
            'recordId' => (string) $row->record_id,
            'kind' => $kind,
            'submittedOn' => $submittedOn,
            'title' => $title,
            'resource' => is_string($row->resource) && $row->resource !== '' ? $row->resource : null,
            'type' => self::type($row->resource),
            'deletedAt' => self::instant($row->created_at),
            'purgesAt' => self::instant($row->purges_at),
            'employeeId' => $employeeId,
            'employeeIdNo' => $idNo,
            'employeeName' => $known['name'] ?? ($idNo ?? 'Member'),
        ];
    }

    /**
     * @return array{id: string, name: string, idNo: ?string}
     */
    public static function employee(int $id, string $name, ?string $idNo): array
    {
        return [
            'id' => (string) $id,
            'name' => $name,
            'idNo' => $idNo,
        ];
    }

    /**
     * @param  array<int, array{name: string, idNo: ?string}>  $employees
     * @return array<string, mixed>
     */
    public static function detail(Recycle $row, array $employees): array
    {
        $item = self::item($row, $employees);
        $payload = is_array($row->payload) ? $row->payload : [];

        return [
            ...$item,
            ...self::generatedReport($payload, $item),
        ];
    }

    /**
     * The generated report a member saw, stored on delete. Older rows without
     * that object still show hours from the restore lines, never the lines themselves.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{
     *     kind: string,
     *     submittedOn: ?string,
     *     employeeId: ?int,
     *     employeeIdNo: ?string,
     *     employeeName: string
     * }  $item
     * @return array{
     *     referenceCode: string,
     *     memberName: string,
     *     reason: ?string,
     *     entries: list<array<string, mixed>>
     * }
     */
    public static function generatedReport(array $payload, array $item): array
    {
        $referenceCode = is_string($item['employeeIdNo']) && $item['employeeIdNo'] !== ''
            ? $item['employeeIdNo']
            : (string) ($item['employeeId'] ?? '');
        $memberName = $item['employeeName'];
        $reason = null;
        $entries = [];

        $stored = is_array($payload['report'] ?? null) ? $payload['report'] : null;
        if ($stored !== null) {
            $storedCode = trim((string) ($stored['referenceCode'] ?? ''));
            if ($storedCode !== '') {
                $referenceCode = $storedCode;
            }
            $storedName = trim((string) ($stored['memberName'] ?? ''));
            if ($storedName !== '') {
                $memberName = $storedName;
            }
            $reason = PortalSubmittedReportPresenter::reason($stored['reason'] ?? null);
            $rawEntries = is_array($stored['entries'] ?? null) ? $stored['entries'] : [];
            foreach ($rawEntries as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $id = trim((string) ($entry['id'] ?? ''));
                $entries[] = PortalSubmittedReportPresenter::entry(
                    $id !== '' ? $id : (string) $index,
                    self::labelList($entry['projectLabel'] ?? null),
                    self::labelList($entry['activityLabel'] ?? null),
                    self::labelList($entry['earnCodeLabel'] ?? null),
                    $entry['hoursRendered'] ?? 0,
                    $entry['elementChange'] ?? 0,
                );
            }
        } else {
            $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                if ($reason === null) {
                    $reason = PortalSubmittedReportPresenter::reason($line['remarks'] ?? null);
                }
                $id = trim((string) ($line['id'] ?? ''));
                $entries[] = PortalSubmittedReportPresenter::entry(
                    $id !== '' ? $id : (string) $index,
                    [],
                    [],
                    [],
                    $line['hours_rendered'] ?? 0,
                    $line['change_in_elements'] ?? 0,
                );
            }
        }

        return [
            'referenceCode' => $referenceCode,
            'memberName' => $memberName,
            'reason' => $reason,
            'entries' => $entries,
        ];
    }

    /**
     * @return list<string>
     */
    private static function labelList(mixed $value): array
    {
        $label = trim((string) $value);

        return $label === '' ? [] : [$label];
    }

    public static function title(mixed $stored, string $kind): string
    {
        $value = trim((string) $stored);
        if ($value !== '') {
            return $value;
        }

        return $kind === PortalSubmittedReportPresenter::KIND_LATE ? 'Late Report' : 'Daily Report';
    }

    public static function kind(mixed $value): string
    {
        $kind = strtolower(trim((string) $value));
        if ($kind === PortalSubmittedReportPresenter::KIND_LATE) {
            return PortalSubmittedReportPresenter::KIND_LATE;
        }

        return PortalSubmittedReportPresenter::KIND_DAILY;
    }

    public static function type(mixed $resource): string
    {
        $value = is_string($resource) ? strtolower(trim($resource)) : '';
        if ($value !== '' && str_starts_with($value, 'request')) {
            return self::TYPE_REQUEST;
        }
        if ($value !== '' && str_starts_with($value, 'project')) {
            return self::TYPE_PROJECT;
        }

        return self::TYPE_REPORT;
    }

    /**
     * @return array{total: int}
     */
    public static function counts(int $total): array
    {
        return ['total' => $total];
    }

    private static function optionalDate(mixed $value): ?string
    {
        $date = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private static function instant(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->clone()->utc()->toIso8601String();
        }

        return Carbon::parse((string) $value)->utc()->toIso8601String();
    }
}
