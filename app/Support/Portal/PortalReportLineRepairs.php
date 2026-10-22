<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Action;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair of the lines the portal itself filed before 2026-10-22: the earn code the old
 * "contains regular" picker chose, and an Actual Work Date it left empty.
 *
 * A line counts as portal-filed only when a reports.submitted ledger action names it, so lines
 * imported from Airtable are never touched. Dry run by default; every applied repair ledgers a
 * reports.submitted edit so the sync sends the corrected values.
 */
final class PortalReportLineRepairs
{
    public function __construct(private readonly CoreLedger $ledger) {}

    /**
     * @return list<array{lineId: int, reportDate: ?string, actualWorkDate: ?string, currentEarn: ?string, targetEarn: ?string, needsEarn: bool, needsDate: bool}>
     */
    public function pending(): array
    {
        $lineIds = $this->portalLineIds();
        if ($lineIds === []) {
            return [];
        }

        $target = (new PortalEarnCodes)->regularWorkingDayId();
        $buggy = $this->buggyEarnCodeId();
        $earnIds = $this->earnIdsByLine($lineIds);
        $descriptions = DB::connection('portal')->table('earn_codes')->pluck('description', 'id');

        $repairs = [];
        foreach (DB::connection('portal')->table('user_reports')->whereIn('id', $lineIds)->orderBy('id')->get(['id', 'report_date', 'actual_work_date']) as $line) {
            $current = [];
            foreach ($earnIds[(int) $line->id] ?? [] as $earnId) {
                $current[$earnId] = $descriptions[$earnId] ?? null;
            }
            $needsEarn = $buggy !== null && $target !== null && $buggy !== $target && array_key_exists($buggy, $current);
            $needsDate = $line->actual_work_date === null;
            if (! $needsEarn && ! $needsDate) {
                continue;
            }
            $repairs[] = [
                'lineId' => (int) $line->id,
                'reportDate' => $line->report_date === null ? null : substr((string) $line->report_date, 0, 10),
                'actualWorkDate' => $line->actual_work_date === null ? null : substr((string) $line->actual_work_date, 0, 10),
                'currentEarn' => $current === [] ? null : reset($current),
                'targetEarn' => $needsEarn ? PortalEarnCodes::REGULAR_WORKING_DAY : null,
                'needsEarn' => $needsEarn,
                'needsDate' => $needsDate,
            ];
        }

        return $repairs;
    }

    /**
     * @param  list<array{lineId: int, reportDate: ?string, needsEarn: bool, needsDate: bool, targetEarn: ?string}>  $repairs
     */
    public function apply(array $repairs): int
    {
        if ($repairs === []) {
            return 0;
        }
        $target = (new PortalEarnCodes)->regularWorkingDayId();

        $this->ledger->within($this->connection(), function () use ($repairs, $target): void {
            foreach ($repairs as $repair) {
                $lineId = (int) $repair['lineId'];
                $fields = [];
                if ($repair['needsEarn'] && $target !== null) {
                    $this->connection()->table('user_reports_earn_codes')->where('user_report_id', $lineId)->delete();
                    $this->connection()->table('user_reports_earn_codes')->insert([
                        'user_report_id' => $lineId,
                        'earn_code_id' => $target,
                    ]);
                    $fields['earnCodeId'] = $target;
                }
                if ($repair['needsDate'] && $repair['reportDate'] !== null) {
                    $this->connection()->table('user_reports')->where('id', $lineId)->update(['actual_work_date' => $repair['reportDate']]);
                    $fields['actual_work_date'] = $repair['reportDate'];
                }
                if ($fields === []) {
                    continue;
                }
                $this->ledger->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    'portal.user_reports',
                    ['id' => (string) $lineId, 'lineIds' => [$lineId], 'fields' => $fields],
                    'reports.submitted',
                    (string) $lineId,
                );
            }
        });

        return count($repairs);
    }

    /**
     * The first earn code by id whose label contains "regular": what the old picker returned.
     */
    public function buggyEarnCodeId(): ?int
    {
        foreach (DB::connection('portal')->table('earn_codes')->orderBy('id')->get(['id', 'description']) as $row) {
            if (str_contains(strtolower(PortalSubmittedReportPresenter::earnCodeLabel($row->description ?? null)), 'regular')) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function portalLineIds(): array
    {
        $ids = [];
        foreach (Action::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('resource', 'reports.submitted')
            ->whereNotNull('parameters')
            ->select(['parameters'])
            ->cursor() as $action) {
            $payload = $action->parameters['parameters'] ?? [];
            foreach (is_array($payload['lineIds'] ?? null) ? $payload['lineIds'] : [] as $id) {
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<int>  $lineIds
     * @return array<int, list<int>>
     */
    private function earnIdsByLine(array $lineIds): array
    {
        $ids = [];
        foreach ($this->connection()->table('user_reports_earn_codes')->whereIn('user_report_id', $lineIds)->orderBy('user_report_id')->get(['user_report_id', 'earn_code_id']) as $row) {
            $ids[(int) $row->user_report_id][] = (int) $row->earn_code_id;
        }

        return $ids;
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
