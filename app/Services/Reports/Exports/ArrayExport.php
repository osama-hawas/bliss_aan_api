<?php

namespace App\Services\Reports\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * A small computed sheet (indicators); right-to-left in Arabic.
 */
class ArrayExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly ReportDataset $dataset,
        private readonly array $rows,
        private readonly string $locale,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->dataset->headings();
    }

    public function title(): string
    {
        return mb_substr($this->dataset->title(), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft($this->locale === 'ar');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
            },
        ];
    }
}
