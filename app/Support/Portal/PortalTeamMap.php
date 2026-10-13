<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Manage / Map (2026-10-13, user): where each active person lives, as PortalHomeLocations keeps it: the
 * home they set themselves, else their address on record, else their region. Only administrators open
 * it (manage.map).
 */
final class PortalTeamMap
{
    public const RULE = 'manage.map';

    /**
     * @return array{section: string, resource: string, data: array{people: list<array<string, mixed>>, unlocated: int}}
     */
    public function list(): array
    {
        $connection = $this->connection();
        $active = $connection->table('employees')->whereRaw("LOWER(COALESCE(employees.status, '')) = 'active'");
        $rows = (clone $active)
            ->join('employee_home_locations as home', 'home.employee_id', '=', 'employees.id')
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get([
                'employees.id', 'employees.first_name', 'employees.last_name', 'employees.department',
                'employees.address', 'employees.location', 'home.latitude', 'home.longitude', 'home.source',
            ]);
        $photos = PortalEmployeePresenter::photoUrls($rows->pluck('id')->all());

        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'department' => trim((string) $row->department) ?: null,
                'photoUrl' => $photos[(int) $row->id] ?? null,
                'latitude' => (float) $row->latitude,
                'longitude' => (float) $row->longitude,
                'place' => self::place($row),
                // address when the address itself was placed; region when only the region could be.
                'source' => (string) $row->source,
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'map',
            'data' => [
                'people' => $people,
                // Active people with neither an address nor a region that could be placed.
                'unlocated' => max(0, (clone $active)->count() - count($people)),
            ],
        ];
    }

    // The town and province, not the street: the last two parts of the address, else the region.
    private static function place(object $row): ?string
    {
        if ($row->source !== 'region') {
            $parts = PortalHomeLocations::addressParts((string) $row->address);
            if ($parts !== []) {
                return implode(', ', array_slice($parts, -2));
            }
        }

        return trim((string) $row->location) ?: null;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
