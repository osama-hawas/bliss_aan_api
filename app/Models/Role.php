<?php

namespace App\Models;

use App\Enums\AdminRole;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Admin role (spatie, guard `api`). System roles are the five `AdminRole` cases; custom roles carry their own
 * AR/EN label (D168).
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'label' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === AdminRole::SuperAdmin->value;
    }

    /**
     * The label in the request language: the enum label for a system role, the stored label for a custom one.
     */
    public function displayLabel(): string
    {
        $system = AdminRole::tryFrom($this->name);
        if ($system !== null) {
            return $system->label();
        }

        $labels = $this->label ?? [];

        return $labels[app()->getLocale()] ?? $labels['ar'] ?? $labels['en'] ?? $this->name;
    }
}
