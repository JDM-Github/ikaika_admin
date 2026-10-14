<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The report forms' saved templates (2026-10-14, user): each person's own recipes, one row each,
 * read and written only by their owner. A template is the builder's rows as labels -- a project
 * label and its activities with hours -- plus the remarks, so it refills the picker as text.
 */
final class PortalReportTemplates
{
    private const MAX_TEMPLATES = 50;

    private const MAX_PROJECTS = 50;

    private const MAX_ACTIVITIES = 50;

    /**
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    public function list(Employee $actor): array
    {
        $rows = $this->connection()->table('report_templates')
            ->where('employee_id', $actor->getKey())
            ->orderBy('id')
            ->get();

        $templates = [];
        foreach ($rows as $row) {
            $templates[] = self::present($row);
        }

        return ['section' => 'reports', 'resource' => 'templates', 'data' => $templates];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $data = $this->validated($request);
        $count = $this->connection()->table('report_templates')->where('employee_id', $actor->getKey())->count();
        if ($count >= self::MAX_TEMPLATES) {
            abort(422, 'You already have '.self::MAX_TEMPLATES.' saved templates. Remove one first.');
        }

        $id = (int) $this->connection()->table('report_templates')->insertGetId([
            'employee_id' => (int) $actor->getKey(),
            'name' => $data['name'],
            'notes' => $data['notes'],
            'body' => json_encode(['projects' => $data['projects']]),
            'date_created' => now(),
            'date_updated' => null,
        ]);

        return ['section' => 'reports', 'resource' => 'templates', 'data' => self::present($this->row($actor, $id))];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $id): array
    {
        $this->row($actor, $id);
        $data = $this->validated($request);

        $this->connection()->table('report_templates')->where('id', $id)->update([
            'name' => $data['name'],
            'notes' => $data['notes'],
            'body' => json_encode(['projects' => $data['projects']]),
            'date_updated' => now(),
        ]);

        return ['section' => 'reports', 'resource' => 'templates', 'data' => self::present($this->row($actor, $id))];
    }

    public function destroy(Employee $actor, int $id): void
    {
        $this->row($actor, $id);
        $this->connection()->table('report_templates')->where('id', $id)->delete();
    }

    // Only its owner's row answers; anyone else reads a 404, so another person's template stays unseen.
    private function row(Employee $actor, int $id): object
    {
        $row = $this->connection()->table('report_templates')
            ->where('id', $id)
            ->where('employee_id', $actor->getKey())
            ->first();
        if ($row === null) {
            abort(404, 'That template was not found.');
        }

        return $row;
    }

    /**
     * @return array{name: string, notes: ?string, projects: list<array{projectLabel: ?string, activities: list<array{activityLabel: ?string, hours: string}>}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'projects' => ['present', 'array', 'max:'.self::MAX_PROJECTS],
        ]);

        $name = trim((string) $data['name']);
        if ($name === '') {
            abort(422, 'Give the template a name.');
        }

        $projects = [];
        foreach ($data['projects'] as $project) {
            if (! is_array($project) || ! is_array($project['activities'] ?? null) || count($project['activities']) > self::MAX_ACTIVITIES) {
                abort(422, 'That template is malformed.');
            }
            $projectLabel = $project['projectLabel'] ?? null;
            if ($projectLabel !== null && ! is_string($projectLabel)) {
                abort(422, 'That template is malformed.');
            }

            $activities = [];
            foreach ($project['activities'] as $activity) {
                $activityLabel = is_array($activity) ? ($activity['activityLabel'] ?? null) : null;
                $hours = is_array($activity) ? ($activity['hours'] ?? null) : null;
                if (($activityLabel !== null && ! is_string($activityLabel)) || ! is_string($hours)) {
                    abort(422, 'That template is malformed.');
                }
                $activities[] = ['activityLabel' => $activityLabel, 'hours' => $hours];
            }

            $projects[] = ['projectLabel' => $projectLabel, 'activities' => $activities];
        }

        $notes = trim((string) ($data['notes'] ?? ''));

        return ['name' => $name, 'notes' => $notes === '' ? null : (string) $data['notes'], 'projects' => $projects];
    }

    /**
     * @return array{id: string, name: string, notes: string, projects: list<array<string, mixed>>}
     */
    private static function present(object $row): array
    {
        $body = json_decode((string) $row->body, true);
        $projects = is_array($body) && is_array($body['projects'] ?? null) ? $body['projects'] : [];

        return [
            'id' => (string) (int) $row->id,
            'name' => (string) $row->name,
            'notes' => $row->notes === null ? '' : (string) $row->notes,
            'projects' => array_values($projects),
        ];
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
