<?php

namespace App\Services\Reports\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Formula-injection-safe cell binding for exports (review H1). Numbers (int/float) stay numbers; every other value is
 * written as an explicit string, so `=HYPERLINK(…)` in a name is text and `+9665…` phones keep their form. A string
 * that starts with a formula trigger (`=`, `@`, tab, CR, or `+`/`-` not followed by a plain number) is also prefixed
 * with `'`, so it stays text if the file is re-saved as CSV.
 */
trait SafeCells
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }
        if ($value === null || $value === '') {
            $cell->setValueExplicit(null, DataType::TYPE_NULL);

            return true;
        }

        $cell->setValueExplicit(self::neutralise((string) $value), DataType::TYPE_STRING);

        return true;
    }

    public static function neutralise(string $value): string
    {
        if (preg_match('/^[=@\t\r]/', $value) || (preg_match('/^[+-]/', $value) && ! preg_match('/^[+-][\d .]+$/', $value))) {
            return "'".$value;
        }

        return $value;
    }
}
