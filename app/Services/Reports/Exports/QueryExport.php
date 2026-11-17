<?php

namespace App\Services\Reports\Exports;

use Illuminate\Contracts\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * The `.xlsx` of one dataset query, read in chunks; right-to-left sheet in Arabic.
 */
class QueryExport implements FromQuery, WithCustomChunkSize, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    use SafeCells;

    public function __construct(
        private readonly ReportDataset $dataset,
        private readonly Builder $query,
        private readonly string $locale,
    ) {}

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return $this->dataset->headings();
    }

    public function map($row): array
    {
        return $this->dataset->map((object) (array) $row);
    }

    public function title(): string
    {
        return mb_substr($this->dataset->title(), 0, 31);
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft($this->locale === 'ar');
                $sheet->freezePane('A2');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
            },
        ];
    }
}
