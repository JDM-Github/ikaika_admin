<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who in MIS is who in Jira (2026-10-22, user: the one who works on a report is its issue's assignee too). An
 * active MIS member -- the only people a report can be assigned to -- is found by searching Jira for their work
 * address, which Jira matches even where it hides it; nobody else is ever looked up. Each answer is kept a day,
 * "no account" too, under the member and their address, so a changed address is asked about afresh.
 *
 * Only the account id reaches an issue: never a name or an address. A lookup Jira does not answer throws, and the
 * caller decides -- a change for Jira fails, a read-back leaves the assignee as it was.
 */
final class PortalJiraPeople
{
    private const KEY = 'portal:jira:account:';

    /**
     * @var array<int, array{name: string, address: string}>|null
     */
    private ?array $members = null;

    public function __construct(private readonly PortalJiraClient $jira) {}

    // The Jira account of an active MIS member; null for nobody, anyone else, or a member Jira has no account for.
    public function accountOf(?int $employeeId): ?string
    {
        $member = $employeeId === null ? null : ($this->members()[$employeeId] ?? null);

        return $member === null ? null : $this->lookup((int) $employeeId, $member['address']);
    }

    // The active MIS member a Jira account is, or null for anyone else.
    public function memberOf(string $accountId): ?int
    {
        foreach ($this->members() as $id => $member) {
            if ($this->lookup($id, $member['address']) === $accountId) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Every active MIS member and their Jira account, if any; fresh asks Jira again rather than taking the day's.
     *
     * @return list<array{id: int, name: string, accountId: ?string}>
     */
    public function roster(bool $fresh = false): array
    {
        $roster = [];
        foreach ($this->members() as $id => $member) {
            $roster[] = ['id' => $id, 'name' => $member['name'], 'accountId' => $this->lookup($id, $member['address'], $fresh)];
        }

        return $roster;
    }

    private function lookup(int $employeeId, string $address, bool $fresh = false): ?string
    {
        if ($address === '') {
            return null;
        }
        $key = self::KEY.$this->jira->site().'|'.$employeeId.'|'.sha1($address);
        $kept = $fresh ? null : Cache::get($key);
        if (is_array($kept)) {
            return is_string($kept['account'] ?? null) ? $kept['account'] : null;
        }
        $account = $this->jira->accountByAddress($address);
        Cache::put($key, ['account' => $account], now()->addDay());

        return $account;
    }

    /**
     * @return array<int, array{name: string, address: string}>
     */
    private function members(): array
    {
        if ($this->members !== null) {
            return $this->members;
        }
        $this->members = [];
        $rows = DB::connection('portal')->table('employees')
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = 'mis'")
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
        foreach ($rows as $row) {
            $this->members[(int) $row->id] = [
                'name' => PortalSubmittedReportPresenter::memberName($row->first_name, $row->last_name),
                'address' => strtolower(trim((string) $row->email)),
            ];
        }

        return $this->members;
    }
}
