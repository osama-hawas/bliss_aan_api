<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Provider application lifecycle. Only `active` providers are visible to clients.
 */
enum ProviderStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case NeedsCompletion = 'needs_completion';
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * The only allowed moves (BRD §5): draft → submitted → needs_completion ⇄ submitted → active ⇄ suspended.
     */
    public function canMoveTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft, self::NeedsCompletion => [self::Submitted],
            self::Submitted => [self::NeedsCompletion, self::Active],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active],
        }, true);
    }

    /**
     * The provider may still change the application and its documents.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::NeedsCompletion;
    }
}
