<?php

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * Labels of the plain status strings the report sources read (`lang/{ar,en}/reports.php`). Statuses of slices merged
 * later fall back to a readable form instead of failing (D318).
 */
final class StatusLabels
{
    public static function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return '';
        }

        $key = "reports.status.{$status}";
        $label = __($key);

        return $label === $key ? Str::headline($status) : $label;
    }
}
