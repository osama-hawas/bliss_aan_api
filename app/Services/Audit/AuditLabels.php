<?php

namespace App\Services\Audit;

use Illuminate\Support\Str;

/**
 * Labels of audit actions and subject types (`lang/{ar,en}/audit.php`, D321). Actions are free strings written by
 * every slice (some use dots, e.g. `review.hidden`), so the map is read as a whole array and unknown actions fall back
 * to a readable form.
 */
final class AuditLabels
{
    public static function action(string $action): string
    {
        $labels = __('audit.actions');

        return is_array($labels) && isset($labels[$action]) ? $labels[$action] : Str::headline(str_replace('.', ' ', $action));
    }

    public static function subjectType(string $type): string
    {
        $labels = __('audit.subjects');

        return is_array($labels) && isset($labels[$type]) ? $labels[$type] : Str::headline($type);
    }
}
