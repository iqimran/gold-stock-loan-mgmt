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
            'settings' => $settings->all(),
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
        $settings->update($request->settings());

        return to_route('settings.loans.edit')->with('success', 'Loan settings saved.');
    }
}
