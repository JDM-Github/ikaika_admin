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
}
