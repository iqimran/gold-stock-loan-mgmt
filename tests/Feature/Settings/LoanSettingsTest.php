<?php

namespace Tests\Feature\Settings;

use App\Domain\Alert\AlertSettings;
use App\Domain\Alert\DueInterestQuery;
use App\Domain\Customer\CustomerSummary;
use App\Domain\Interest\InterestSettings;
use App\Domain\Loan\LoanService;
use App\Domain\Loan\LoanSummary;
use App\Domain\Payment\PaymentService;
use App\Domain\Settings\LoanSettings;
use App\Enums\AlertStatus;
use App\Enums\InterestBase;
use App\Enums\Permission;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings → Loan settings: permission, validation, audit, cache — and, above all, what each setting
 * reaches (new loans only / future evaluation / presentation), with no historical amount ever changing.
 */
class LoanSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $payload = [
            'shop' => ['name' => 'Rupali Jewellers', 'address' => '12 Tanti Bazar, Dhaka', 'phone' => '01700-000000', 'receipt_footer' => 'Thank you.'],
            'currency' => ['code' => 'BDT', 'symbol' => '৳'],
            'interest' => ['base' => 'outstanding', 'due' => 'period_end', 'yearly_conversion' => 'twelfths'],
            'loans' => ['default_interest_rate' => '2.5', 'default_interest_rate_type' => 'monthly'],
            'collection' => ['grace_days' => 0, 'alert_threshold' => 3],
            'numbering' => ['customer_prefix' => 'CUS', 'loan_prefix' => 'LN', 'collateral_prefix' => 'COL', 'receipt_prefix' => 'RCPT', 'reset' => 'monthly', 'digits' => 6],
            'lists' => [
                'payment_methods' => ['cash', 'bank', 'mobile_banking', 'card', 'other'],
                'collateral_types' => ['gold', 'diamond', 'mixed', 'other'],
                'karat_options' => ['18', '21', '22', '24'],
                'max_karat' => '24',
            ],
        ];

        // Lists are replaced whole (a recursive merge would blend them index by index).
        foreach ($overrides['lists'] ?? [] as $key => $value) {
            $payload['lists'][$key] = $value;
        }
        unset($overrides['lists']);

        return array_replace_recursive($payload, $overrides);
    }

    private function save(array $overrides = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin())->from('/settings/loans')->put('/settings/loans', $this->payload($overrides));
    }

    private function settings(): LoanSettings
    {
        return app(LoanSettings::class);
    }

    private function activeLoan(string $start = '2026-01-01'): Loan
    {
        Carbon::setTestNow("{$start} 09:00:00");
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => $start]);
        CollateralItem::factory()->for($loan)->create();

        return app(LoanService::class)->activate($loan, User::factory()->create());
    }

    private function runOn(string $date): void
    {
        Carbon::setTestNow("{$date} 00:05:00");
        $this->artisan('loans:process-interest')->assertSuccessful();
    }

    // ── access ──────────────────────────────────────────────────────────────────────────────

    public function test_admin_sees_every_setting_with_what_it_affects(): void
    {
        $this->actingAs($this->admin())->get('/settings/loans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/loans')
                ->where('settings', fn ($settings) => $settings['numbering.loan_prefix'] === 'LN' && $settings['collection.grace_days'] === 0)
                ->where('effects.interest', LoanSettings::EFFECTS['interest'])
                ->where('effects.collection', LoanSettings::EFFECTS['collection'])
                ->where('options.interest_base', ['outstanding']));
    }

    public function test_settings_need_the_settings_manage_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::LoansView->value, Permission::PaymentsCreate->value]);

        $this->actingAs($user)->get('/settings/loans')->assertForbidden();
        $this->save(['shop' => ['name' => 'Hijacked']], $user)->assertForbidden();
        $this->assertSame(0, Setting::count());

        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::SettingsManage->value);
        $this->actingAs($manager)->get('/settings/loans')->assertOk();

        auth()->logout();
        $this->get('/settings/loans')->assertRedirect('/login');
    }

    // ── save, audit, cache ──────────────────────────────────────────────────────────────────

    public function test_saving_stores_only_changed_values_and_audits_them(): void
    {
        $this->assertSame(0, $this->settings()->graceDays()); // warms the cache with the defaults

        $this->save(['collection' => ['grace_days' => 5], 'shop' => ['name' => 'Rupali Jewellers']])
            ->assertRedirect('/settings/loans')
            ->assertSessionHas('success', 'Loan settings saved.');

        $this->assertSame(5, $this->settings()->graceDays()); // cache cleared on save
        $this->assertSame('Rupali Jewellers', $this->settings()->shop()['name']);
        $this->assertSame('2.5', $this->settings()->defaultInterestRate());

        $audit = AuditLog::query()->where('event', 'settings.loans_updated')->sole();
        $this->assertSame(0, $audit->old_values['collection.grace_days']);
        $this->assertSame(5, $audit->new_values['collection.grace_days']);
        $this->assertArrayNotHasKey('numbering.loan_prefix', $audit->new_values); // unchanged values are not logged
        $this->assertNotNull($audit->user_id);

        // Saving the same values again writes nothing and logs nothing.
        $this->save(['collection' => ['grace_days' => 5], 'shop' => ['name' => 'Rupali Jewellers']])->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('event', 'settings.loans_updated')->count());
    }

    public function test_validation_rejects_bad_values_and_normalises_codes(): void
    {
        $this->save([
            'currency' => ['code' => 'TAKA'],
            'numbering' => ['loan_prefix' => 'ln-1', 'digits' => 9, 'receipt_prefix' => 'CUS'],
            'collection' => ['grace_days' => 91, 'alert_threshold' => 0],
            'interest' => ['base' => 'principal'],
            'lists' => ['payment_methods' => [], 'collateral_types' => ['Gold Bar'], 'karat_options' => ['22', '26']],
        ])->assertSessionHasErrors([
            'currency.code', 'numbering.loan_prefix', 'numbering.digits', 'numbering.receipt_prefix', 'numbering.customer_prefix',
            'collection.grace_days', 'collection.alert_threshold', 'interest.base',
            'lists.payment_methods', 'lists.collateral_types.0', 'lists.karat_options.1',
        ]);
        $this->assertSame(0, Setting::count());

        $this->save(['currency' => ['code' => 'usd'], 'numbering' => ['loan_prefix' => 'gl']])->assertSessionHasNoErrors();
        $this->assertSame('USD', $this->settings()->currency()['code']);
        $this->assertSame('GL', $this->settings()->numbering('loan')['prefix']);
    }

    // ── interest method: new loans only ─────────────────────────────────────────────────────

    public function test_interest_method_change_reaches_new_loans_only(): void
    {
        $old = $this->activeLoan();
        $this->runOn('2026-03-01');
        $before = $old->interestPeriods()->orderBy('period_start')->pluck('expected_interest', 'due_date')->all();

        $this->settings()->update(['interest.base' => 'principal', 'interest.yearly_conversion' => 'actual_days']);

        $new = Loan::factory()->create();
        $this->assertSame('principal', $new->interest_base);
        $this->assertSame('actual_days', $new->yearly_rate_conversion);

        $this->assertSame('outstanding', $old->fresh()->interest_base);
        $this->assertSame(InterestBase::Outstanding, InterestSettings::forLoan($old->fresh())->base);

        // The existing loan's periods (generated and future) keep its own method.
        $this->runOn('2026-05-01');
        $after = $old->interestPeriods()->orderBy('period_start')->pluck('expected_interest', 'due_date')->all();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame(['200.00'], array_values(array_unique($after)));
    }

    // ── grace period and alert threshold: all open loans, no amount changes ────────────────

    public function test_grace_period_delays_overdue_and_alerts_without_changing_amounts(): void
    {
        $this->settings()->update(['collection.alert_threshold' => 1]);
        $loan = $this->activeLoan(); // Jan is due 2026-01-31

        $this->settings()->update(['collection.grace_days' => 5]);
        $this->runOn('2026-02-03');

        $summary = app(LoanSummary::class)->for($loan->fresh());
        $this->assertSame('due', $summary['periods'][0]['status']);
        $this->assertSame(0, $summary['consecutive_missed']);
        $this->assertSame('200.00', $summary['interest_due']);
        $this->assertSame(0, Alert::query()->where('loan_id', $loan->id)->count());
        $this->actingAs($this->admin())->getJson('/api/v1/loans?overdue=1')->assertJsonCount(0, 'data');
        $this->assertSame(0, app(DueInterestQuery::class)->totals(['status' => 'overdue'])['periods']);
        $this->assertSame(1, app(DueInterestQuery::class)->totals(['status' => 'due'])['periods']);
        $this->assertFalse(Customer::query()->whereKey($loan->customer_id)->tap(fn ($q) => app(CustomerSummary::class)->whereOverdue($q))->exists());

        // Past the grace period (due 31 Jan + 5 days → overdue from 6 Feb).
        $this->runOn('2026-02-06');
        $summary = app(LoanSummary::class)->for($loan->fresh());
        $this->assertSame('overdue', $summary['periods'][0]['status']);
        $this->assertSame(1, $summary['consecutive_missed']);
        $this->assertSame('200.00', $summary['interest_due']);
        $this->assertSame(AlertStatus::Open, Alert::query()->where('loan_id', $loan->id)->sole()->status);
        $this->assertSame('200.00', app(DueInterestQuery::class)->totals(['status' => 'overdue'])['amount']);
        $this->assertTrue(Customer::query()->whereKey($loan->customer_id)->tap(fn ($q) => app(CustomerSummary::class)->whereOverdue($q))->exists());

        // Removing the grace period applies at the next evaluation; expected interest never moves.
        $this->settings()->update(['collection.grace_days' => 0]);
        $this->assertSame('200.00', $loan->interestPeriods()->orderBy('period_start')->value('expected_interest'));
        $this->assertSame(1, app(AlertSettings::class)->missedPeriodThreshold);
    }

    // ── numbering: numbers issued from now on ───────────────────────────────────────────────

    public function test_number_format_applies_to_new_numbers_only(): void
    {
        Carbon::setTestNow('2026-12-15 10:00:00');
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $terms = ['customer' => $customer->customer_no, 'principal' => '50000', 'interest_rate' => '2.5', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-12-15'];

        $this->actingAs($admin)->post('/loans', $terms)->assertRedirect('/loans/LN-202612-000001');

        $this->settings()->update(['numbering.loan_prefix' => 'GL', 'numbering.reset' => 'yearly', 'numbering.digits' => 4]);

        $this->actingAs($admin)->post('/loans', $terms)->assertRedirect('/loans/GL-2026-0001');
        $this->assertSame(['GL-2026-0001', 'LN-202612-000001'], Loan::query()->orderBy('loan_no')->pluck('loan_no')->all());
    }

    // ── lists: choices for new entries ──────────────────────────────────────────────────────

    public function test_lists_drive_validation_of_new_entries(): void
    {
        $this->settings()->update([
            'lists.collateral_types' => ['gold', 'bangle'],
            'lists.payment_methods' => ['cash', 'bkash'],
            'lists.max_karat' => '22',
        ]);
        $admin = $this->admin();
        $draft = Loan::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/loans/{$draft->loan_no}/collateral", ['type' => 'diamond', 'weight_grams' => '10', 'karat' => '24', 'estimated_value' => '1000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'karat']);
        $this->actingAs($admin)->postJson("/api/v1/loans/{$draft->loan_no}/collateral", ['type' => 'bangle', 'weight_grams' => '10', 'karat' => '22', 'estimated_value' => '1000'])
            ->assertCreated();

        $loan = $this->activeLoan('2026-10-01');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->actingAs($admin)->postJson('/api/v1/payments', ['loan' => $loan->loan_no, 'type' => 'interest', 'amount' => '100', 'method' => 'card', 'payment_date' => '2026-11-05', 'idempotency_key' => 'settings-key-1'])
            ->assertJsonValidationErrors('method');
        $this->actingAs($admin)->postJson('/api/v1/payments', ['loan' => $loan->loan_no, 'type' => 'interest', 'amount' => '100', 'method' => 'bkash', 'payment_date' => '2026-11-05', 'idempotency_key' => 'settings-key-2'])
            ->assertCreated();

        $this->actingAs($admin)->get("/loans/{$draft->loan_no}")
            ->assertInertia(fn (Assert $page) => $page->where('collateralTypes', ['gold', 'bangle'])->where('maxKarat', '22'));
    }

    // ── presentation: shop, currency, new-loan defaults ─────────────────────────────────────

    public function test_shop_details_currency_and_defaults_reach_the_screens(): void
    {
        $loan = $this->activeLoan('2026-10-01');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $payment = app(PaymentService::class)->post($loan->fresh(), ['type' => 'interest', 'amount' => '100', 'method' => 'cash', 'payment_date' => '2026-11-05'], User::factory()->create());

        $this->settings()->update([
            'shop.name' => 'Rupali Jewellers', 'shop.receipt_footer' => 'Come again',
            'currency.code' => 'USD', 'currency.symbol' => '$',
            'loans.default_interest_rate' => '3.25', 'loans.default_interest_rate_type' => 'yearly',
        ]);
        $admin = $this->admin();

        // Receipts (including reprints of old payments) show the current shop details; the amount is untouched.
        $this->actingAs($admin)->get("/payments/{$payment->receipt_no}/receipt")
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.name', 'Rupali Jewellers')
                ->where('shop.receipt_footer', 'Come again')
                ->where('currency', ['code' => 'USD', 'symbol' => '$'])
                ->where('payment.amount', '100.00'));

        $this->actingAs($admin)->get('/loans/create')
            ->assertInertia(fn (Assert $page) => $page->where('defaults', ['interest_rate' => '3.25', 'interest_rate_type' => 'yearly']));
    }

    // ── branding: shop name and logo ────────────────────────────────────────────────────────

    public function test_the_shop_name_brands_every_screen_including_the_sign_in_page(): void
    {
        $this->save(['shop' => ['name' => 'Rupali Jewellers']])->assertSessionHasNoErrors();
        auth()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('<title inertia>Rupali Jewellers</title>', false)
            ->assertInertia(fn (Assert $page) => $page->where('name', 'Rupali Jewellers')->where('branding', ['name' => 'Rupali Jewellers', 'logo_url' => null]));

        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Rupali Jewellers'));
    }

    public function test_a_logo_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake(LoanSettings::LOGO_DISK);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/settings/loans', [...$this->payload(), '_method' => 'put', 'logo' => UploadedFile::fake()->image('logo.png', 200, 80)])
            ->assertSessionHasNoErrors();
        $first = $this->settings()->logoPath();
        $this->assertStringStartsWith('branding/logo-', $first);
        Storage::disk(LoanSettings::LOGO_DISK)->assertExists($first);
        $logoUrl = $this->settings()->branding()['logo_url'];
        $this->assertStringContainsString('/branding/logo?v=', $logoUrl);

        // Shown to guests (sign-in page) and served publicly, safely.
        auth()->logout();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', $logoUrl))->assertSee('<link rel="icon"', false);
        $this->get('/branding/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/png');

        // Saving other settings keeps the logo.
        $this->actingAs($admin)->put('/settings/loans', $this->payload(['collection' => ['grace_days' => 2]]))->assertSessionHasNoErrors();
        $this->assertSame($first, $this->settings()->logoPath());

        // Replacing deletes the old file; the change is audited.
        $this->actingAs($admin)->post('/settings/loans', [...$this->payload(), '_method' => 'put', 'logo' => UploadedFile::fake()->image('new.webp', 100, 100)]);
        $second = $this->settings()->logoPath();
        $this->assertNotSame($first, $second);
        Storage::disk(LoanSettings::LOGO_DISK)->assertMissing($first);
        $audit = AuditLog::query()->where('event', 'settings.loans_updated')->latest('id')->first();
        $this->assertSame($first, $audit->old_values['shop.logo_path']);
        $this->assertSame($second, $audit->new_values['shop.logo_path']);

        // Removing goes back to the built-in icon.
        $this->actingAs($admin)->put('/settings/loans', [...$this->payload(), 'remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull($this->settings()->logoPath());
        Storage::disk(LoanSettings::LOGO_DISK)->assertMissing($second);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_only_small_raster_images_are_accepted_as_a_logo(): void
    {
        Storage::fake(LoanSettings::LOGO_DISK);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        foreach ([$svg, UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'), UploadedFile::fake()->image('tiny.png', 10, 10), UploadedFile::fake()->image('big.jpg', 500, 500)->size(2048)] as $file) {
            $this->actingAs($this->admin())->post('/settings/loans', [...$this->payload(), '_method' => 'put', 'logo' => $file])->assertSessionHasErrors('logo');
        }

        $this->assertNull($this->settings()->logoPath());
        $this->assertSame([], Storage::disk(LoanSettings::LOGO_DISK)->allFiles());
    }
}
