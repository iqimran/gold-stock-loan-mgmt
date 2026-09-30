<?php

namespace App\Http\Controllers;

use App\Domain\Settings\LoanSettings;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shop logo (Settings → Loan settings). Public, because the sign-in page shows it; it serves only the
 * one configured file (no path comes from the request), which was validated as a raster image on upload.
 */
class BrandingLogoController extends Controller
{
    public function __invoke(LoanSettings $settings): StreamedResponse
    {
        $path = $settings->logoPath();
        $disk = Storage::disk(LoanSettings::LOGO_DISK);
        abort_unless($path !== null && $disk->exists($path), 404);

        return $disk->response($path, null, [
            // URLs are versioned (?v=…), so the file can be cached; a new logo gets a new URL.
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
