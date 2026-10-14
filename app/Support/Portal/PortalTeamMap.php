<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Manage / Map (2026-10-13, user): where each active person lives, as PortalHomeLocations keeps it: the
 * home they set themselves, else their address on record, else their region. Only administrators open
 * it (manage.map), and the map follows that rule's reach like every page: a department reach shows the
 * department, every department shows everyone.
 */
final class PortalTeamMap
{
    public const RULE = 'manage.map';

    /**
     * @return array{section: string, resource: string, data: array{people: list<array<string, mixed>>, unplaced: list<array<string, mixed>>}}
     */
    public function list(Employee $actor, Request $request): array
    {
        // The people the rule lets this actor open: never an executive, and within their reach.
        $inReach = PortalRules::people($actor, $request, self::RULE);
        $ids = array_map(static fn (Employee $person): int => (int) $person->getKey(), $inReach);
        if ($ids === []) {
            return ['section' => 'manage', 'resource' => 'map', 'data' => ['people' => [], 'unplaced' => []]];
        }

        $connection = $this->connection();
        $rows = $connection->table('employees')
            ->whereIn('employees.id', $ids)
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
                // gps/map when they set their own home; address/region when it comes from their record.
                'source' => (string) $row->source,
            ];
        }

        // Everyone in reach without a placed point, named so the page can say who is missing.
        $placed = array_map(static fn (array $person): int => (int) $person['id'], $people);
        $missing = $connection->table('employees')
            ->whereIn('employees.id', $ids)
            ->whereNotIn('employees.id', $placed)
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get(['employees.id', 'employees.first_name', 'employees.last_name', 'employees.department']);
        $missingPhotos = PortalEmployeePresenter::photoUrls($missing->pluck('id')->all());

        $unplaced = [];
        foreach ($missing as $row) {
            $unplaced[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'department' => trim((string) $row->department) ?: null,
                'photoUrl' => $missingPhotos[(int) $row->id] ?? null,
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'map',
            'data' => [
                'people' => $people,
                'unplaced' => $unplaced,
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
