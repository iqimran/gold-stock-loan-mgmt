<?php

namespace App\Domain\Reporting\Export;

use Illuminate\Support\Str;

/**
 * Everything an export contains, independent of the file format: title, the filters applied (as
 * human-readable lines), typed columns, the rows and the report's totals. Built by ExportDatasets from
 * the same report/search queries as the screens; rendered by PdfExporter and ExcelExporter.
 */
final readonly class ExportDataset
{
    /**
     * @param  array<string, string>  $filters  label => value
     * @param  list<ExportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows  keyed by column key
     * @param  list<array{label: string, values: array<string, mixed>}>  $totals  summary rows under the data
     */
    public function __construct(
        public string $title,
        public array $filters,
        public array $columns,
        public array $rows,
        public array $totals = [],
        public string $orientation = 'landscape',
    ) {}

    public function filename(string $extension): string
    {
        return Str::slug($this->title).'-'.now()->format('Ymd-His').'.'.$extension;
    }
}
