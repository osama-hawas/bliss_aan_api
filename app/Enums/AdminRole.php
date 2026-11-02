<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Admin staff roles (spatie/laravel-permission, guard `api`).
 */
enum AdminRole: string
{
    use HasLabel;

    case SuperAdmin = 'super_admin';
    case AccountsOfficer = 'accounts_officer';
    case OperationsSupport = 'operations_support';
    case StoreContentOfficer = 'store_content_officer';
    case DeliveryOfficer = 'delivery_officer';
}
