<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Manage / Project Settings: the reference data a project is filed under. A scope here is an activity
 * code a non-Engineering department reports on (activity_codes); adding one offers it on Add/Edit
 * Project and the report builder for its department. Engineering's scopes are the WBS tree, read only.
 */
final class PortalProjectSettings
{
    public function __construct(private readonly PortalAudit $audit) {}

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function index(): array
    {
        $scopes = [];
        foreach ($this->connection()->table('activity_codes')->orderBy('department')->orderBy('id_no')->get(['id', 'id_no', 'name', 'department']) as $row) {
            $scopes[] = [
                'id' => (string) (int) $row->id,
                'code' => trim((string) $row->id_no),
                'name' => trim((string) $row->name),
                'department' => trim((string) $row->department),
            ];
        }

        return ['section' => 'manage', 'resource' => 'project-settings', 'data' => ['scopes' => $scopes]];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addScope(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ]);
        $code = trim($data['code']);
        if ($code === '') {
            abort(422, 'Give the scope a code.');
        }
        if ($this->taken($code)) {
            abort(422, 'That scope code is already on the list.');
        }

        $id = (int) $this->connection()->table('activity_codes')->insertGetId([
            'id_no' => $code,
            'name' => trim($data['name']),
            'department' => trim($data['department']),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'projects.settings.scope', '{UserName|You} added the scope '.$code, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateScope(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('activity_codes')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That scope was not found.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ]);
        $code = trim($data['code']);
        if ($code === '') {
            abort(422, 'Give the scope a code.');
        }
        if ($this->taken($code, $id)) {
            abort(422, 'That scope code is already on the list.');
        }

        $this->connection()->table('activity_codes')->where('id', $id)->update([
            'id_no' => $code,
            'name' => trim($data['name']),
            'department' => trim($data['department']),
        ]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'projects.settings.scope', '{UserName|You} changed the scope '.$code, (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeScope(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('activity_codes')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That scope was not found.');
        }

        $this->connection()->table('activity_codes')->where('id', $id)->delete();
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'projects.settings.scope', '{UserName|You} removed the scope '.trim((string) $row->id_no), (string) $id, $request);

        return $this->index();
    }

    private function taken(string $code, ?int $exceptId = null): bool
    {
        $query = $this->connection()->table('activity_codes')->whereRaw('LOWER(TRIM(id_no)) = ?', [mb_strtolower($code)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    // The report builder and the boards read these codes through caches; bump both so a change shows.
    private function bump(): void
    {
        (new PortalReportProjects)->bumpCache();
        PortalCache::bump();
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
