<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Manage / Map (2026-10-13, user): where each active person last saved something, from the location
 * every portal write carries (RequirePortalLocation, stored on logs). It is the last located action,
 * not live tracking, and only administrators open it (manage.map).
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
        $latest = $connection->table('logs')
            ->whereNotNull('location_lat')
            ->whereNotNull('location_lng')
            ->groupBy('employee_id')
            ->selectRaw('employee_id, MAX(id) as id');
        $rows = $connection->table('employees')
            ->joinSub($latest, 'latest', 'latest.employee_id', '=', 'employees.id')
            ->join('logs', 'logs.id', '=', 'latest.id')
            ->whereRaw("LOWER(COALESCE(employees.status, '')) = 'active'")
            ->orderBy('employees.last_name')->orderBy('employees.first_name')
            ->get([
                'employees.id', 'employees.first_name', 'employees.last_name', 'employees.department',
                'logs.location_lat', 'logs.location_lng', 'logs.location_label', 'logs.created_at',
            ]);
        $photos = PortalEmployeePresenter::photoUrls($rows->pluck('id')->all());

        $people = [];
        foreach ($rows as $row) {
            $people[] = [
                'id' => (string) (int) $row->id,
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'department' => trim((string) $row->department) ?: null,
                'photoUrl' => $photos[(int) $row->id] ?? null,
                'latitude' => (float) $row->location_lat,
                'longitude' => (float) $row->location_lng,
                'place' => trim((string) $row->location_label) ?: null,
                'locatedAt' => (string) $row->created_at,
            ];
        }
        $active = $connection->table('employees')->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->count();

        return [
            'section' => 'manage',
            'resource' => 'map',
            'data' => [
                'people' => $people,
                // Active people who have not saved anything with a location yet.
                'unlocated' => max(0, $active - count($people)),
            ],
        ];
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
