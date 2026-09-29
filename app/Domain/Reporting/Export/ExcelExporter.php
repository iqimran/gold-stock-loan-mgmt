<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Settings\LoanSettings;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders an ExportDataset as an .xlsx workbook (Laravel Excel).
 */
class ExcelExporter
{
    public function __construct(private readonly LoanSettings $settings) {}

    public function download(ExportDataset $dataset, ?User $user): BinaryFileResponse
    {
        $generated = 'Generated: '.now()->format('Y-m-d H:i').' ('.config('app.timezone').')'.($user ? " by {$user->name}" : '').' · '.count($dataset->rows).' row(s) · Amounts in '.$this->settings->currency()['code'];

        return Excel::download(new DatasetSheet($dataset, $generated), $dataset->filename('xlsx'));
    }
}
