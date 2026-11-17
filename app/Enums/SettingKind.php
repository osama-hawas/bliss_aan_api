<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The value shape of an app setting (Slice 20 settings editor, D307).
 */
enum SettingKind: string
{
    use HasLabel;

    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Boolean = 'boolean';
    case IntegerList = 'integer_list';
    case UploadLimits = 'upload_limits';
    case Json = 'json';
}
