<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Settings\LoanSettings;
use App\Enums\InterestDueTiming;
use App\Enums\InterestRateType;
use App\Enums\Permission;
use App\Enums\YearlyRateConversion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LoanSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Loan settings (docs/01 "Settings"). Admin only (settings.manage); every save is audited.
 */
class LoanSettingsController extends Controller
{
    public function edit(LoanSettings $settings): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        return Inertia::render('settings/loans', [
            'settings' => array_diff_key($settings->all(), ['shop.logo_path' => true]),
            'logoUrl' => $settings->branding()['logo_url'],
            'effects' => LoanSettings::EFFECTS,
            'options' => [
                'interest_base' => LoanSettings::INTEREST_BASES,
                'interest_due' => array_column(InterestDueTiming::cases(), 'value'),
                'yearly_conversion' => array_column(YearlyRateConversion::cases(), 'value'),
                'rate_types' => InterestRateType::values(),
            ],
        ]);
    }

    public function update(LoanSettingsRequest $request, LoanSettings $settings): RedirectResponse
    {
        $values = $request->settings();
        $previousLogo = $settings->logoPath();

        if ($request->hasFile('logo')) {
            $values['shop.logo_path'] = $settings->storeLogo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $values['shop.logo_path'] = null;
        }

        try {
            $settings->update($values);
        } catch (\Throwable $e) {
            if (isset($values['shop.logo_path']) && $values['shop.logo_path'] !== $previousLogo) {
                Storage::disk(LoanSettings::LOGO_DISK)->delete($values['shop.logo_path']);
            }

            throw $e;
        }

        // The replaced / removed logo file goes only once the new setting is saved.
        if ($previousLogo !== null && array_key_exists('shop.logo_path', $values) && $values['shop.logo_path'] !== $previousLogo) {
            Storage::disk(LoanSettings::LOGO_DISK)->delete($previousLogo);
        }

        return to_route('settings.loans.edit')->with('success', 'Loan settings saved.');
    }
}
