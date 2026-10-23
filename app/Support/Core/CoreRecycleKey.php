<?php

namespace App\Support\Core;

/**
 * Recycle keys that a later add can recognise. Always pair with CoreLedger's product field.
 */
final class CoreRecycleKey
{
    public static function submittedReport(int $employeeId, string $date, string $kind): string
    {
        return $employeeId.':'.$date.'-'.$kind;
    }

    public static function overtimeRequest(int $employeeId, string $date): string
    {
        return $employeeId.':'.$date.'-overtime';
    }

    public static function offsetRequest(int $employeeId, string $date): string
    {
        return $employeeId.':'.$date.'-offset';
    }

    public static function leaveRequest(int $employeeId, string $date): string
    {
        return $employeeId.':'.$date.'-leave';
    }

    public static function reimbursementRequest(int $employeeId, string $date, int $claimId): string
    {
        return $employeeId.':'.$date.'-reimbursement-'.$claimId;
    }

    /**
     * Keyed on the project alone, not the actor. A project belongs to the company rather than to
     * whoever deleted it, and the unique key on (product, recycle_key) is what stops the same
     * project sitting in the bin twice after two people delete and restore it in turn.
     */
    public static function project(int $projectId): string
    {
        return 'project-'.$projectId;
    }

    // An issue or request is the company's too (2026-10-21), so keyed on itself alone, as a project is.
    public static function bugReport(int $bugReportId): string
    {
        return 'bug-report-'.$bugReportId;
    }
}
