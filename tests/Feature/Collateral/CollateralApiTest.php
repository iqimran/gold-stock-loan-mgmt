<?php

namespace Tests\Feature\Collateral;

use App\Enums\CollateralStatus;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Collateral lifecycle (business-decided rules: see App\Domain\Collateral\CollateralService).
 */
class CollateralApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 11:00:00');
    }

    private function actingWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));
        Sanctum::actingAs($user);

        return $user;
    }

    private function valid(array $overrides = []): array
    {
        return ['type' => 'gold', 'weight_grams' => '11.664', 'karat' => '22', 'estimated_value' => '95000.50', 'description' => 'Pair of bangles', ...$overrides];
    }

    private function item(LoanStatus $loanStatus = LoanStatus::Active, array $attributes = []): CollateralItem
    {
        return CollateralItem::factory()->for(Loan::factory()->status($loanStatus))->create($attributes);
    }

    // ── add ─────────────────────────────────────────────────────────────────────────────────

    public function test_collateral_is_added_to_a_loan_and_recorded_in_its_history(): void
    {
        $user = $this->actingWith(Permission::CollateralCreate, Permission::LoansView);
        $loan = Loan::factory()->create();

        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.collateral_no', 'COL-202610-000001')
            ->assertJsonPath('data.loan.loan_no', $loan->loan_no)
            ->assertJsonPath('data.type', 'gold')
            ->assertJsonPath('data.weight_grams', '11.664')
            ->assertJsonPath('data.karat', '22.00')
            ->assertJsonPath('data.estimated_value', '95000.50')
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.received_at', now()->toIso8601String())
            ->assertJsonPath('data.released_at', null)
            ->assertJsonMissingPath('data.id');

        $item = CollateralItem::sole();
        $this->assertSame($loan->id, $item->loan_id);
        $this->assertSame($user->id, $item->created_by);

        $event = $loan->events()->sole();
        $this->assertSame(LoanEventType::CollateralAdded, $event->event_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('COL-202610-000001', $event->payload['collateral_no']);
        $this->assertSame('11.664', $event->payload['details']['weight_grams']);
    }

    public function test_diamond_collateral_without_karat_and_a_backdated_intake(): void
    {
        $this->actingWith(Permission::CollateralCreate, Permission::LoansView);
        $loan = Loan::factory()->create();

        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid(['type' => 'diamond', 'karat' => null, 'received_at' => '2026-10-01 09:30:00']))
            ->assertCreated()
            ->assertJsonPath('data.karat', null)
            ->assertJsonPath('data.received_at', Carbon::parse('2026-10-01 09:30:00')->toIso8601String());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidCollateral(): array
    {
        return [
            'missing fields' => [['type' => null, 'weight_grams' => null, 'estimated_value' => null], ['type', 'weight_grams', 'estimated_value']],
            'zero weight' => [['weight_grams' => '0'], ['weight_grams']],
            'negative weight' => [['weight_grams' => '-1.5'], ['weight_grams']],
            'weight with 4 decimals' => [['weight_grams' => '1.0001'], ['weight_grams']],
            'weight above DECIMAL(12,3)' => [['weight_grams' => '1000000000'], ['weight_grams']],
            'zero karat' => [['karat' => '0'], ['karat']],
            'karat above 24' => [['karat' => '24.01'], ['karat']],
            'karat with 3 decimals' => [['karat' => '21.555'], ['karat']],
            'zero value' => [['estimated_value' => '0'], ['estimated_value']],
            'negative value' => [['estimated_value' => '-100'], ['estimated_value']],
            'value with 3 decimals' => [['estimated_value' => '100.001'], ['estimated_value']],
            'non-numeric value' => [['estimated_value' => 'a lot'], ['estimated_value']],
            'unknown type' => [['type' => 'silver'], ['type']],
            'future intake' => [['received_at' => '2026-10-06 00:00:00'], ['received_at']],
            'status from input' => [['status' => 'released'], ['status']],
        ];
    }

    #[DataProvider('invalidCollateral')]
    public function test_invalid_collateral_is_rejected(array $overrides, array $errors): void
    {
        $this->actingWith(Permission::CollateralCreate, Permission::LoansView);
        $loan = Loan::factory()->create();

        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('collateral_items', 0);
    }

    #[DataProvider('loanStatuses')]
    public function test_collateral_is_accepted_only_by_draft_and_open_loans(LoanStatus $status, bool $accepted): void
    {
        $this->actingWith(Permission::CollateralCreate, Permission::LoansView);
        $loan = Loan::factory()->status($status)->create();

        $response = $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid());

        if ($accepted) {
            $response->assertCreated();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors(['loan' => "Collateral cannot be added to a {$status->value} loan."]);
            $this->assertDatabaseCount('collateral_items', 0);
        }
    }

    public static function loanStatuses(): array
    {
        return [
            'draft' => [LoanStatus::Draft, true],
            'active' => [LoanStatus::Active, true],
            'overdue' => [LoanStatus::Overdue, true],
            'closed' => [LoanStatus::Closed, false],
            'cancelled' => [LoanStatus::Cancelled, false],
        ];
    }

    public function test_collateral_types_follow_configuration(): void
    {
        $this->actingWith(Permission::CollateralCreate, Permission::LoansView);
        $loan = Loan::factory()->create();
        config(['loans.collateral.types' => ['gold', 'silver']]);

        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid(['type' => 'silver']))->assertCreated();
        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid(['type' => 'diamond']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'gold, silver']);
    }

    // ── update ──────────────────────────────────────────────────────────────────────────────

    public function test_draft_loan_collateral_is_corrected_without_a_reason_and_audited(): void
    {
        $user = $this->actingWith(Permission::CollateralUpdate);
        $item = $this->item(LoanStatus::Draft);

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['weight_grams' => '11.7', 'estimated_value' => '96000'])
            ->assertOk()
            ->assertJsonPath('data.weight_grams', '11.700')
            ->assertJsonPath('data.estimated_value', '96000.00');

        $event = $item->loan->events()->sole();
        $this->assertSame(LoanEventType::CollateralUpdated, $event->event_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertEquals(['weight_grams' => '11.664', 'estimated_value' => '90000.00'], $event->payload['before']);
        $this->assertEquals(['weight_grams' => '11.700', 'estimated_value' => '96000.00'], $event->payload['after']);
        $this->assertSame($user->id, $item->fresh()->updated_by);
    }

    #[DataProvider('runningLoans')]
    public function test_corrections_on_a_running_loan_require_a_reason(LoanStatus $status): void
    {
        $this->actingWith(Permission::CollateralUpdate);
        $item = $this->item($status);

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['karat' => '21'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'A reason is required to change collateral of '.($status === LoanStatus::Active || $status === LoanStatus::Overdue ? 'an' : 'a')." {$status->value} loan."]);
        $this->assertSame('22.00', $item->fresh()->karat);

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['karat' => '21', 'reason' => 'Re-tested at the counter'])
            ->assertOk()
            ->assertJsonPath('data.karat', '21.00');

        $this->assertSame('Re-tested at the counter', $item->loan->events()->sole()->payload['reason']);
    }

    public static function runningLoans(): array
    {
        return ['active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue], 'closed (not yet released)' => [LoanStatus::Closed]];
    }

    public function test_unchanged_values_are_not_recorded_or_blocked(): void
    {
        $this->actingWith(Permission::CollateralUpdate);
        $item = $this->item();

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['weight_grams' => '11.664', 'karat' => '22.0'])->assertOk();

        $this->assertSame(0, $item->loan->events()->count());
    }

    public function test_collateral_cannot_move_to_another_loan(): void
    {
        $this->actingWith(Permission::CollateralUpdate);
        $item = $this->item(LoanStatus::Draft);
        $other = Loan::factory()->create();

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['loan' => $other->loan_no])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loan' => 'cannot be moved to another loan']);

        $this->assertSame($item->loan_id, $item->fresh()->loan_id);
    }

    public function test_released_collateral_cannot_be_changed(): void
    {
        $this->actingWith(Permission::CollateralUpdate);
        $item = $this->item(LoanStatus::Closed, ['status' => CollateralStatus::Released, 'released_at' => now(), 'released_by' => User::factory()->create()->id]);

        $this->patchJson("/api/v1/collateral/{$item->collateral_no}", ['description' => 'Changed', 'reason' => 'Try'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'A released collateral item cannot be changed.']);
    }

    // ── release ─────────────────────────────────────────────────────────────────────────────

    #[DataProvider('finishedLoans')]
    public function test_collateral_of_a_finished_loan_is_released_with_actor_time_and_reason(LoanStatus $status): void
    {
        $user = $this->actingWith(Permission::CollateralRelease, Permission::CollateralView);
        $item = $this->item($status);

        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => 'Returned to the customer'])
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.released_at', now()->toIso8601String())
            ->assertJsonPath('data.released_by', $user->name)
            ->assertJsonPath('data.actions.release', false);

        $item->refresh();
        $this->assertSame($user->id, $item->released_by);
        $this->assertEquals(['collateral_no' => $item->collateral_no, 'reason' => 'Returned to the customer'], $item->loan->events()->sole()->payload);

        // Released items stay in history.
        $this->getJson("/api/v1/collateral/{$item->collateral_no}")->assertOk()->assertJsonPath('data.status', 'released');
        $this->getJson('/api/v1/collateral?status=released')->assertJsonPath('meta.total', 1);
    }

    public static function finishedLoans(): array
    {
        return ['closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    #[DataProvider('unfinishedLoans')]
    public function test_collateral_of_a_running_loan_cannot_be_released(LoanStatus $status): void
    {
        $this->actingWith(Permission::CollateralRelease);
        $item = $this->item($status);

        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => 'Customer asked'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "only after the loan is closed or cancelled (loan is {$status->value})"]);

        $this->assertSame(CollateralStatus::Held, $item->fresh()->status);
        $this->assertNull($item->fresh()->released_at);
    }

    public static function unfinishedLoans(): array
    {
        return ['draft' => [LoanStatus::Draft], 'active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue]];
    }

    public function test_release_requires_a_reason_and_happens_only_once(): void
    {
        $this->actingWith(Permission::CollateralRelease);
        $item = $this->item(LoanStatus::Closed);

        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => 'Returned'])->assertOk();
        $releasedAt = $item->fresh()->released_at;

        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => 'Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'already been released']);

        $this->assertEquals($releasedAt, $item->fresh()->released_at);
        $this->assertSame(1, $item->loan->events()->count());
    }

    // ── no deletion ─────────────────────────────────────────────────────────────────────────

    public function test_collateral_cannot_be_deleted(): void
    {
        $this->actingWith(...Permission::cases());
        $item = $this->item(LoanStatus::Draft);

        $this->deleteJson("/api/v1/collateral/{$item->collateral_no}")->assertMethodNotAllowed();
        $this->assertModelExists($item);
    }

    // ── authorization ───────────────────────────────────────────────────────────────────────

    public function test_each_endpoint_requires_its_permission(): void
    {
        $loan = Loan::factory()->create();
        $closedItem = $this->item(LoanStatus::Closed);

        $this->actingWith(Permission::CollateralView, Permission::LoansView);
        $this->getJson('/api/v1/collateral')->assertOk();
        $this->getJson("/api/v1/collateral/{$closedItem->collateral_no}")->assertOk();
        $this->getJson("/api/v1/loans/{$loan->loan_no}/collateral")->assertOk();
        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid())->assertForbidden();
        $this->patchJson("/api/v1/collateral/{$closedItem->collateral_no}", ['description' => 'x', 'reason' => 'Test'])->assertForbidden();
        $this->postJson("/api/v1/collateral/{$closedItem->collateral_no}/release", ['reason' => 'Returned'])->assertForbidden();

        // collateral.update does not grant the sensitive release action.
        $this->actingWith(Permission::CollateralUpdate);
        $this->postJson("/api/v1/collateral/{$closedItem->collateral_no}/release", ['reason' => 'Returned'])->assertForbidden();

        $this->actingWith(Permission::CollateralCreate);
        $this->getJson('/api/v1/collateral')->assertForbidden();
        $this->postJson("/api/v1/loans/{$loan->loan_no}/collateral", $this->valid())->assertForbidden(); // cannot see the loan

        $this->assertSame(CollateralStatus::Held, $closedItem->fresh()->status);
        $this->assertDatabaseCount('collateral_items', 1);
    }

    public function test_guests_are_unauthenticated_and_items_are_addressed_by_number(): void
    {
        $item = $this->item();
        $this->getJson('/api/v1/collateral')->assertUnauthorized();

        $this->actingWith(Permission::CollateralView);
        $this->getJson("/api/v1/collateral/{$item->id}")->assertNotFound();
    }

    // ── list / search / filter ──────────────────────────────────────────────────────────────

    public function test_loan_collateral_lists_every_item_of_that_loan(): void
    {
        $this->actingWith(Permission::CollateralView, Permission::LoansView);
        $loan = Loan::factory()->status(LoanStatus::Closed)->create();
        CollateralItem::factory()->for($loan)->count(2)->create();
        CollateralItem::factory()->for($loan)->create(['status' => CollateralStatus::Released, 'released_at' => now(), 'released_by' => User::factory()->create()->id]);
        $this->item();

        $this->getJson("/api/v1/loans/{$loan->loan_no}/collateral")->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson("/api/v1/loans/{$loan->loan_no}/collateral?status=held")->assertJsonPath('meta.total', 2);
    }

    public function test_search_filters_and_pagination(): void
    {
        $this->actingWith(Permission::CollateralView);
        $karim = Customer::factory()->create(['name' => 'Karim Uddin']);
        $ring = CollateralItem::factory()->for(Loan::factory()->for($karim))->create(['type' => 'diamond', 'karat' => null, 'description' => 'Solitaire ring', 'received_at' => '2026-09-01 10:00:00']);
        $chain = CollateralItem::factory()->create(['description' => 'Gold chain', 'received_at' => '2026-10-02 10:00:00']);

        $numbers = fn (string $query) => collect($this->getJson("/api/v1/collateral?{$query}")->assertOk()->json('data'))->pluck('collateral_no')->sort()->values()->all();

        $this->assertSame([$ring->collateral_no], $numbers('q=karim'));
        $this->assertSame([$ring->collateral_no], $numbers('q=SOLITAIRE'));
        $this->assertSame([$chain->collateral_no], $numbers('q='.strtolower($chain->collateral_no)));
        $this->assertSame([$ring->collateral_no], $numbers("customer={$karim->customer_no}"));
        $this->assertSame([$chain->collateral_no], $numbers("loan={$chain->loan->loan_no}"));
        $this->assertSame([$ring->collateral_no], $numbers('type=diamond'));
        $this->assertSame([$chain->collateral_no], $numbers('received_from=2026-10-01'));
        $this->assertSame([$ring->collateral_no], $numbers('received_to=2026-09-30'));

        $this->getJson('/api/v1/collateral?per_page=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.collateral_no', $chain->collateral_no) // newest intake first
            ->assertJsonPath('data.0.loan.customer.name', $chain->loan->customer->name);

        $this->getJson('/api/v1/collateral?type=silver&status=lost')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'status']);
    }
}
