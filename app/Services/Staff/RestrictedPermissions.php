<?php

namespace App\Services\Staff;

use App\Enums\Permission;

/**
 * Permissions that no role but `super_admin` may ever hold (D169, D64): private client attachments.
 * The role editor refuses them (`422 PERMISSION_RESTRICTED`) and the seeder never gives them elsewhere.
 */
final class RestrictedPermissions
{
    public const SUPER_ADMIN_ONLY = [Permission::RequestsAttachmentsView->value];

    /**
     * @param  list<string>  $permissions
     * @return list<string> the restricted names found in the list
     */
    public static function in(array $permissions): array
    {
        return array_values(array_intersect(self::SUPER_ADMIN_ONLY, $permissions));
    }
}
