<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * ClashFlow's mock workflow, one JSON document per ClashFlow project plus the shared playbook library
 * (2026-10-21, user: ClashFlow talks to the admin through the API only, so its SQL lives here).
 * Keys: "project-<clashflow_projects.id>" and "library". Anyone who may see a project reads its state;
 * only its lead and coordinators write it (a viewer reads). The library is shared by everyone signed in.
 * Last write wins, as in the mock.
 * ponytail: whole-document rows; per-record tables with versions replace them (ClashFlow plan R2).
 */
final class PortalClashFlowStates
{
    public const LIBRARY = 'library';

    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly PortalClashFlowProjects $projects) {}

    /**
     * The library and the state of every ClashFlow project the person may see, as raw JSON by key.
     *
     * @return array<string, string>
     */
    public function mine(Employee $employee): array
    {
        $keys = [self::LIBRARY, ...array_map(static fn (array $project): string => 'project-'.$project['id'], $this->projects->forEmployee($employee))];

        return $this->connection()->table('clashflow_states')
            ->whereIn('state_key', $keys)
            ->pluck('state', 'state_key')
            ->map(static fn (mixed $state): string => (string) $state)
            ->all();
    }

    public function save(Employee $employee, string $key, string $state): void
    {
        $projectId = $this->projectIdOf($key);
        if ($projectId !== null) {
            $role = $this->projects->roleOn($employee, $projectId);
            if ($role === null) {
                abort(403, 'You are not on this project.');
            }
            if ($role === PortalClashFlowProjects::ROLE_VIEWER) {
                abort(403, 'Viewers cannot change this project.');
            }
        }
        if (strlen($state) > self::MAX_BYTES) {
            abort(413, 'The workflow state is too large.');
        }
        json_decode($state);
        if (json_last_error() !== JSON_ERROR_NONE) {
            abort(422, 'The workflow state is not valid JSON.');
        }

        $this->connection()->table('clashflow_states')->updateOrInsert(
            ['state_key' => $key],
            ['clashflow_project_id' => $projectId, 'state' => $state, 'updated_by' => (int) $employee->getKey(), 'updated_at' => now()],
        );
    }

    // Reset demo: the projects the person works on (lead or coordinator) and the shared library go back to the seed.
    public function reset(Employee $employee): void
    {
        $keys = [self::LIBRARY, ...array_map(static fn (int $id): string => 'project-'.$id, $this->projects->writableIds($employee))];
        $this->connection()->table('clashflow_states')->whereIn('state_key', $keys)->delete();
    }

    // "project-12" -> 12, "library" -> null; anything else is not a key.
    private function projectIdOf(string $key): ?int
    {
        if ($key === self::LIBRARY) {
            return null;
        }
        if (preg_match('/^project-([1-9][0-9]{0,9})$/', $key, $match) !== 1) {
            abort(404, 'Unknown workflow state.');
        }

        return (int) $match[1];
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
