<?php

namespace App\Services\Reports;

use App\Models\User;

/**
 * "Any one of these permissions" for report scope (D317). Unknown permission names count as not held.
 */
final class StaffAccess
{
    /**
     * @param  list<string>  $permissions
     */
    public static function any(User $staff, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($staff->checkPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }
}
