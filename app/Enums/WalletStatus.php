<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * D106: a wallet is `active` until its owner deletes it; then it is `closed` and kept as history.
 */
enum WalletStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Closed = 'closed';
}
