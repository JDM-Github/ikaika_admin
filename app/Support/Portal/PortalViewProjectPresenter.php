<?php

namespace App\Support\Portal;

use Carbon\Carbon;

/**
 * View Projects board. Admin and Executive see every project with an isAssigned flag;
 * members see only the projects they are on.
 *
 * Shapes the board's project details, scope summary, links, and trail history.
 */
final class PortalViewProjectPresenter
{
    /**
     * @param  object{
     *     id: int|string,
     *     project_number: mixed,
     *     project_name: mixed,
     *     department: mixed,
     *     status: mixed,
     *     type_of_job: mixed,
     *     area_sqft: mixed,
     *     project_lead_email: mixed,
     *     forma_link: mixed,
     *     msteams_project_link: mixed,
     *     ms_planner_link: mixed,
     *     panoramic_link: mixed,
     *     ms_loop_link: mixed,
     *     google_drive_folder_path: mixed,
     *     is_assigned: mixed,
     *     is_lead?: mixed
     * }  $row
     * @param  list<string>  $memberNames
     * @param  array<string, string>  $leadsByEmail
     * @param  list<string>  $clientNames
     * @param  array{
     *     tierLabel: string,
     *     total: int
     * }|null  $scopeSummary
     * @param  list<array{
     *     id: string,
     *     changedOn: string,
     *     actorName: string,
     *     summary: string,
     *     actorPhotoUrl: string|null
     * }>  $trail
     * @param  list<string>  $scopeCodes
     * @param  array<string, string>  $memberPhotos
     * @return array<string, mixed>
     */
    public static function item(
        object $row,
        array $memberNames,
        array $leadsByEmail,
        array $clientNames,
        ?array $scopeSummary,
        array $trail,
        array $scopeCodes = [],
        array $memberPhotos = [],
    ): array {
        $id = (string) (int) $row->id;
        [$number, $name] = PortalProjectEditor::split($row);
        // A project with no number is listed by its id, and sorts after the numbered ones.
        $code = $number ?? $id;
        if ($name === '') {
            $name = 'Project';
        }

        $department = trim((string) ($row->department ?? ''));
        $type = trim((string) ($row->type_of_job ?? ''));
        $leadEmail = strtolower(trim((string) ($row->project_lead_email ?? '')));
        $leadName = $leadEmail !== '' ? ($leadsByEmail[$leadEmail] ?? $leadEmail) : 'Unassigned';

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'clientName' => $clientNames !== [] ? implode(', ', $clientNames) : 'Unassigned',
            'leadName' => $leadName,
            'departmentName' => $department !== '' ? $department : 'Unassigned',
            'typeLabel' => $type,
            'status' => self::status($row->status ?? null),
            'creationStatus' => self::creationStatus($row->project_creation_status ?? null),
            'areaSquareFeet' => self::area($row->area_sqft ?? null),
            'memberNames' => $memberNames,
            'memberPhotos' => $memberPhotos,
            'isAssigned' => (bool) ($row->is_assigned ?? false),
            // The person reading the board leads this project, so the list can say so.
            'isLeading' => (bool) ($row->is_lead ?? false),
            'scopeSummary' => $scopeSummary ?? [
                'tierLabel' => '',
                'total' => 0,
            ],
            'scopeCodes' => $scopeCodes,
            'trail' => $trail,
            'links' => self::links($row),
            'numbered' => $number !== null,
        ];
    }

    /**
     * Project Team: the lead first, then the members, then the support members; the lead once, even
     * when they are a member too.
     *
     * @param  list<array{id: int, name: string, role?: string}>  $members
     * @return list<array{name: string, role: string}>
     */
    public static function team(?string $leadName, array $members): array
    {
        $team = $leadName === null ? [] : [['name' => $leadName, 'role' => 'lead']];
        foreach (['member', 'support'] as $role) {
            foreach ($members as $member) {
                if (($member['role'] ?? 'member') !== $role || in_array($member['name'], array_column($team, 'name'), true)) {
                    continue;
                }
                $team[] = ['name' => $member['name'], 'role' => $role];
            }
        }

        return $team;
    }

    public static function status(mixed $status): string
    {
        $value = strtolower(trim((string) $status));
        if ($value === '' || $value === 'started' || $value === 'ongoing' || $value === 'in progress') {
            return 'ongoing';
        }
        if (in_array($value, ['done', 'completed', 'complete', 'closed'], true)) {
            return 'completed';
        }
        if (in_array($value, ['on hold', 'on-hold', 'hold'], true)) {
            return 'on-hold';
        }
        if (in_array($value, ['cancelled', 'canceled'], true)) {
            return 'cancelled';
        }

        return 'ongoing';
    }

    // Airtable's own rows all say Approved, Pending or Rejected; anything else reads as approved.
    public static function creationStatus(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'approved',
        };
    }

    public static function instant(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->clone()->utc()->toIso8601String();
        }

        return Carbon::parse((string) $value)->utc()->toIso8601String();
    }

    private static function area(mixed $area): float
    {
        if ($area === null || $area === '') {
            return 0.0;
        }
        $value = (float) $area;

        return $value > 0 ? round($value, 2) : 0.0;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private static function links(object $row): array
    {
        $candidates = [
            'Forma' => $row->forma_link ?? null,
            'MS Teams' => $row->msteams_project_link ?? null,
            'MS Planner' => $row->ms_planner_link ?? null,
            'Panoramic' => $row->panoramic_link ?? null,
            'MS Loop' => $row->ms_loop_link ?? null,
            'Google Drive' => $row->google_drive_folder_path ?? null,
        ];
        $links = [];
        foreach ($candidates as $label => $raw) {
            $url = trim((string) $raw);
            if ($url === '') {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }
}
