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
}
