<?php

namespace Tests\Concerns;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Filing a real report through the API on the seeded data: a day the person has not filed yet, one of
 * their own projects and an activity code, in the shape the report builder sends.
 */
trait FilesPortalReports
{
    protected function freeReportDate(Employee $employee, int $daysAgo = 0): string
    {
        for ($offset = $daysAgo; $offset < $daysAgo + 400; $offset++) {
            $candidate = Carbon::today()->subDays($offset)->toDateString();
            $taken = DB::connection('portal')->table('user_reports')
                ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
                ->where('employees_user_reports.employee_id', $employee->getKey())
                ->where('user_reports.report_date', $candidate)
                ->exists();
            if (! $taken) {
                return $candidate;
            }
        }

        $this->fail('No free report date was available.');
    }

    // A weekday far enough ahead that nobody has a report or a request on it yet.
    protected function freeRequestDay(Employee $employee): string
    {
        $name = strtolower(PortalSubmittedReportPresenter::memberName(
            is_string($employee->first_name) ? $employee->first_name : null,
            is_string($employee->last_name) ? $employee->last_name : null,
        ));
        for ($offset = 60; $offset < 400; $offset++) {
            $day = Carbon::today()->addDays($offset);
            if ($day->isWeekend()) {
                continue;
            }
            $candidate = $day->toDateString();
            $requested = DB::connection('portal')->table('requests')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->where('request_date', $candidate)
                ->exists();
            if (! $requested && $this->freeReportDate($employee, -$offset) === $candidate) {
                return $candidate;
            }
        }

        $this->fail('No free request day was available.');
    }

    /**
     * @return list<array{projectLabel: string, activityLabel: string, hoursRendered: float}>
     */
    protected function reportEntries(Employee $employee, float $hours = 5.0): array
    {
        $project = DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employee->getKey())
            ->whereNotNull('projects.project_name')
            ->select(['projects.project_number', 'projects.project_name'])
            ->first();
        $activity = DB::connection('portal')->table('activity_codes')->first();
        $this->assertNotNull($project);
        $this->assertNotNull($activity);

        return [[
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel($project->project_number ?? null, $project->project_name ?? null),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel($activity->name ?? null, $activity->id_no ?? null),
            'hoursRendered' => $hours,
        ]];
    }

    /**
     * @return array{kind: string, reports: list<array<string, mixed>>}
     */
    protected function dailyReportBody(Employee $employee, string $date): array
    {
        return ['kind' => 'daily', 'reports' => [['reportDate' => $date, 'entries' => $this->reportEntries($employee)]]];
    }
}
