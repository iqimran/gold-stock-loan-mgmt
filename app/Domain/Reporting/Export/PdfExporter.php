<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Payment\PaymentReceipt;
use App\Domain\Settings\LoanSettings;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an ExportDataset as a PDF (docs/10 "Export — PDF"): shop header, title, filter summary,
 * generated timestamp, the rows, totals and "Page X of Y" on every page. Remote resources stay disabled.
 */
class PdfExporter
{
    public function __construct(private readonly PaymentReceipt $receipts) {}

    public function download(ExportDataset $dataset, ?User $user): Response
    {
        $pdf = Pdf::loadView('exports.dataset', [
            'dataset' => $dataset,
            'shop' => $this->receipts->shop(),
            'currency' => app(LoanSettings::class)->currency(),
            'generatedAt' => now()->format('Y-m-d H:i').' ('.config('app.timezone').')',
            'generatedBy' => $user?->name,
        ])->setPaper('a4', $dataset->orientation)
            // Embed only the glyphs used (the full DejaVu font would make every PDF ~0.9 MB).
            ->setOption('isFontSubsettingEnabled', true);

        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 110,
            $canvas->get_height() - 24,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('helvetica'),
            8,
        );

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$dataset->filename('pdf').'"',
        ]);
    }
}
