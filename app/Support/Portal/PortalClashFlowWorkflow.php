<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * ClashFlow's real workflow records (2026-10-21, P2). Each group and batch is its own row with an
 * optimistic version; the package/run/level map is one project row. Every applied write records an
 * audit row. Roles are checked here, never trusted from the desktop client.
 */
final class PortalClashFlowWorkflow
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const TYPES = ['project', 'group', 'batch'];

    public function __construct(private readonly PortalClashFlowProjects $projects) {}

    /**
     * The records a person may read, plus the recent audit trail.
     *
     * @return array{records: list<array<string, mixed>>, audit: list<array<string, mixed>>}
     */
    public function snapshot(Employee $employee, int $projectId): array
    {
        if ($this->projects->roleOn($employee, $projectId) === null) {
            abort(403, 'You are not on this project.');
        }

        $connection = $this->connection();
        $records = $connection->table('clashflow_records')
            ->where('clashflow_project_id', $projectId)
            ->orderBy('id')
            ->get(['record_type', 'record_key', 'payload', 'version'])
            ->map(fn (object $row): array => [
                'type' => (string) $row->record_type,
                'key' => (string) $row->record_key,
                'payload' => (string) $row->payload,
                'version' => (int) $row->version,
            ])->all();
        $audit = $connection->table('clashflow_audit')
            ->where('clashflow_project_id', $projectId)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['created_at', 'actor_employee_id', 'text'])
            ->reverse()
            ->map(fn (object $row): array => [
                'at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'actor' => $row->actor_employee_id === null ? 'System' : 'Employee #'.$row->actor_employee_id,
                'text' => (string) $row->text,
            ])->values()->all();

        return ['records' => $records, 'audit' => $audit];
    }

    /**
     * Apply per-record writes. Non-conflicting records are saved; a stale version is returned in
     * `conflicts` and the whole request answers 409 from the controller.
     *
     * @param  list<array{type: string, key: string, payload: string, version: int}>  $records
     * @return array{records: list<array<string, mixed>>, conflicts: list<array<string, mixed>>}
     */
    public function apply(Employee $employee, int $projectId, array $records): array
    {
        $role = $this->projects->roleOn($employee, $projectId);
        if ($role === null) {
            abort(403, 'You are not on this project.');
        }
        if ($role === PortalClashFlowProjects::ROLE_VIEWER) {
            abort(403, 'Viewers cannot change this project.');
        }

        $bytes = 0;
        foreach ($records as $record) {
            $bytes += strlen((string) $record['payload']);
        }
        if ($bytes > self::MAX_BYTES) {
            abort(413, 'The workflow update is too large.');
        }

        $connection = $this->connection();
        $seed = ! $connection->table('clashflow_records')->where('clashflow_project_id', $projectId)->exists();
        $applied = [];
        $conflicts = [];

        $connection->transaction(function () use ($employee, $projectId, $role, $records, $seed, $connection, &$applied, &$conflicts): void {
            foreach ($records as $input) {
                $type = (string) $input['type'];
                $key = (string) $input['key'];
                $payload = (string) $input['payload'];
                $version = (int) $input['version'];
                if (! in_array($type, self::TYPES, true)) {
                    abort(422, 'Unknown workflow record type.');
                }
                $data = json_decode($payload, true);
                if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
                    abort(422, 'A workflow record is not valid JSON.');
                }
                $this->assertKeyMatchesPayload($type, $key, $data);
                $this->authorize($employee, $projectId, $role, $type, $key, $data, $seed);

                $existing = $connection->table('clashflow_records')
                    ->where('clashflow_project_id', $projectId)
                    ->where('record_type', $type)
                    ->where('record_key', $key)
                    ->first();
                if ($existing !== null && (int) $existing->version !== $version) {
                    $conflicts[] = $this->present($existing);

                    continue;
                }
                if ($existing === null && $version !== 0) {
                    $conflicts[] = ['type' => $type, 'key' => $key, 'payload' => null, 'version' => 0];

                    continue;
                }

                $now = now();
                if ($existing === null) {
                    $connection->table('clashflow_records')->insert([
                        'clashflow_project_id' => $projectId,
                        'record_type' => $type,
                        'record_key' => $key,
                        'payload' => $payload,
                        'version' => 1,
                        'updated_by' => (int) $employee->getKey(),
                        'updated_at' => $now,
                    ]);
                    $newVersion = 1;
                    $action = 'create';
                    $before = null;
                } else {
                    $newVersion = (int) $existing->version + 1;
                    $connection->table('clashflow_records')
                        ->where('id', $existing->id)
                        ->update([
                            'payload' => $payload,
                            'version' => $newVersion,
                            'updated_by' => (int) $employee->getKey(),
                            'updated_at' => $now,
                        ]);
                    $action = 'update';
                    $before = (string) $existing->payload;
                }

                $connection->table('clashflow_audit')->insert([
                    'clashflow_project_id' => $projectId,
                    'actor_employee_id' => (int) $employee->getKey(),
                    'action' => $action,
                    'record_type' => $type,
                    'record_key' => $key,
                    'old_version' => $existing === null ? 0 : (int) $existing->version,
                    'new_version' => $newVersion,
                    'before_payload' => $before,
                    'after_payload' => $payload,
                    'text' => ucfirst($action).' '.$type.' '.$key.'.',
                    'created_at' => $now,
                ]);
                $applied[] = ['type' => $type, 'key' => $key, 'payload' => $payload, 'version' => $newVersion];
            }
        });

        return ['records' => $applied, 'conflicts' => $conflicts];
    }

    public function reset(Employee $employee): void
    {
        $ids = $this->projects->writableIds($employee);
        if ($ids === []) {
            return;
        }
        $connection = $this->connection();
        $connection->transaction(function () use ($connection, $ids): void {
            $connection->table('clashflow_audit')->whereIn('clashflow_project_id', $ids)->delete();
            $connection->table('clashflow_records')->whereIn('clashflow_project_id', $ids)->delete();
        });
    }

    private function authorize(Employee $employee, int $projectId, string $role, string $type, string $key, array $payload, bool $seed): void
    {
        if ($seed || $role === PortalClashFlowProjects::ROLE_LEAD) {
            return;
        }

        if ($role !== PortalClashFlowProjects::ROLE_COORDINATOR) {
            abort(403, 'You cannot change this project.');
        }

        if ($type === 'project') {
            abort(403, 'Only the lead can change the project package or run.');
        }

        if ($type === 'group') {
            $batchId = $payload['BatchId'] ?? null;
            if (! is_string($batchId) || $batchId === '') {
                abort(403, 'This group is not in your batch.');
            }
            $batch = $this->connection()->table('clashflow_records')
                ->where('clashflow_project_id', $projectId)
                ->where('record_type', 'batch')
                ->where('record_key', 'batch:'.$batchId)
                ->first(['payload']);
            $ownerId = $batch === null ? null : (json_decode((string) $batch->payload, true)['OwnerId'] ?? null);
            if ((string) $ownerId !== (string) $employee->getKey()) {
                abort(403, 'This group is not in your batch.');
            }

            return;
        }

        // A coordinator may edit only a batch they already own; ownership changes stay with the lead.
        $status = (string) ($payload['Status'] ?? '');
        if (in_array($status, ['Approved', 'Published'], true)) {
            abort(403, 'Only the lead can approve or publish a batch.');
        }
        $batch = $this->connection()->table('clashflow_records')
            ->where('clashflow_project_id', $projectId)
            ->where('record_type', 'batch')
            ->where('record_key', $key)
            ->first(['payload']);
        $ownerId = $batch === null ? null : (json_decode((string) $batch->payload, true)['OwnerId'] ?? null);
        $incomingOwner = $payload['OwnerId'] ?? null;
        if ((string) $ownerId !== (string) $employee->getKey() || (string) $incomingOwner !== (string) $employee->getKey()) {
            abort(403, 'You can only update your own batch.');
        }
    }

    private function assertKeyMatchesPayload(string $type, string $key, array $payload): void
    {
        if ($type === 'project') {
            if ($key !== 'main') {
                abort(422, 'Unknown project record.');
            }

            return;
        }
        $prefix = $type.':';
        if (! str_starts_with($key, $prefix) || ($payload['Id'] ?? null) !== substr($key, strlen($prefix))) {
            abort(422, 'The workflow record key does not match its payload.');
        }
    }

    private function present(object $row): array
    {
        return [
            'type' => (string) $row->record_type,
            'key' => (string) $row->record_key,
            'payload' => (string) $row->payload,
            'version' => (int) $row->version,
        ];
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
