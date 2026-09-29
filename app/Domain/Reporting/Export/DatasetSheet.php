<?php

namespace App\Domain\Reporting\Export;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * An ExportDataset as one worksheet (Laravel Excel), docs/10 "Export — Excel":
 *
 *   title / filter lines / generated line   (merged across the columns; no spacer row: Laravel Excel drops empty rows)
 *   header row                              (bold, auto-filter, frozen)
 *   data rows                               (numbers as numbers, dates as dates, typed number formats)
 *   totals rows                             (bold, the report's server totals — not recalculated)
 */
class DatasetSheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    private int $headerRow;

    public function __construct(
        private readonly ExportDataset $dataset,
        private readonly string $generated,
    ) {
        $this->headerRow = 1 + count($dataset->filters) + 1 + 1; // title, filters, generated, header
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $this->dataset->title), 0, 31);
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [[$this->dataset->title]];

        foreach ($this->dataset->filters as $label => $value) {
            $rows[] = ["{$label}: {$value}"];
        }

        $rows[] = [$this->generated];
        $rows[] = array_map(fn (ExportColumn $column) => $column->label, $this->dataset->columns);

        foreach ($this->dataset->rows as $row) {
            $rows[] = array_map(fn (ExportColumn $column) => $this->cell($column, $row[$column->key] ?? null), $this->dataset->columns);
        }

        foreach ($this->dataset->totals as $total) {
            $rows[] = array_map(
                fn (ExportColumn $column, int $position) => $position === 0 ? $total['label'] : (array_key_exists($column->key, $total['values']) ? $this->cell($column, $total['values'][$column->key]) : null),
                $this->dataset->columns,
                array_keys($this->dataset->columns),
            );
        }

        return $rows;
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count($this->dataset->columns));
                $firstData = $this->headerRow + 1;
                $lastData = $this->headerRow + count($this->dataset->rows);
                $lastRow = $lastData + count($this->dataset->totals);

                // Title block across the table width.
                for ($row = 1; $row < $this->headerRow; $row++) {
                    $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
                }
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

                // Header: bold, filterable, frozen.
                $sheet->getStyle("A{$this->headerRow}:{$lastColumn}{$this->headerRow}")->getFont()->setBold(true);
                $sheet->setAutoFilter("A{$this->headerRow}:{$lastColumn}".max($lastData, $this->headerRow));
                $sheet->freezePane('A'.$firstData);

                // Typed formats on data and totals.
                foreach ($this->dataset->columns as $index => $column) {
                    $format = $this->format($column);
                    if ($format !== null && $lastRow >= $firstData) {
                        $letter = Coordinate::stringFromColumnIndex($index + 1);
                        $sheet->getStyle("{$letter}{$firstData}:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
                    }
                }

                if ($this->dataset->totals !== []) {
                    $sheet->getStyle('A'.($lastData + 1).":{$lastColumn}{$lastRow}")->getFont()->setBold(true);
                }
            },
        ];
    }

    /**
     * Numbers go in as numbers and dates as Excel dates, so sorting, filtering and sums work in Excel.
     */
    private function cell(ExportColumn $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            $column->type === ExportColumn::DATE => ExcelDate::PHPToExcel(CarbonImmutable::parse((string) $value, 'UTC')->startOfDay()),
            $column->type === ExportColumn::INTEGER => (int) $value,
            $column->isNumeric() => 0 + (string) $value,
            default => (string) $value,
        };
    }

    private function format(ExportColumn $column): ?string
    {
        return match ($column->type) {
            ExportColumn::MONEY, ExportColumn::KARAT => '#,##0.00',
            ExportColumn::WEIGHT => '#,##0.000',
            ExportColumn::RATE => '0.0000',
            ExportColumn::INTEGER => '0',
            ExportColumn::DATE => NumberFormat::FORMAT_DATE_YYYYMMDD,
            default => null,
        };
    }
}
