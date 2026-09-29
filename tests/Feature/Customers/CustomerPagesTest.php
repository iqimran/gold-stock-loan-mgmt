<?php

namespace Tests\Feature\Customers;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customer screens (Inertia). Business behaviour is covered by CustomerApiTest / CustomerSummaryTest.
 */
class CustomerPagesTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));

        return $user;
    }

    private function loan(Customer $customer, string $loanNo, string $status): void
    {
        DB::table('loans')->insert([
            'loan_no' => $loanNo, 'customer_id' => $customer->id, 'principal' => '50000.00', 'outstanding_principal' => '40000.00',
            'interest_rate' => '2.5000', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'status' => $status,
            'start_date' => '2026-09-01', 'next_due_date' => '2026-10-31',
        ]);
    }

    public function test_list_renders_paginated_customers_with_summary_and_filters(): void
    {
        Customer::factory()->count(3)->create();
        Customer::factory()->create(['name' => 'Findable Person']);

        $this->actingAs($this->userWith(Permission::CustomersView))
            ->get('/customers?q=findable&min_missed=1&overdue=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/index')
                ->where('filters.q', 'findable')
                ->where('filters.status', 'active')
                ->where('filters.min_missed', '1')
                ->where('filters.overdue', true)
                ->has('customers.meta')
                ->has('customers.data', 0));

        $this->actingAs($this->userWith(Permission::CustomersView))
            ->get('/customers?q=findable')
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Findable Person')
                ->has('customers.data.0.summary', fn (Assert $summary) => $summary
                    ->where('active_loans', 0)
                    ->where('total_interest_due', '0.00')
                    ->etc())
                ->missing('customers.data.0.id'));
    }

    public function test_invalid_filters_return_validation_errors_to_the_page(): void
    {
        $this->actingAs($this->userWith(Permission::CustomersView))
            ->from('/customers')
            ->get('/customers?status=deleted')
            ->assertRedirect('/customers')
            ->assertSessionHasErrors('status');
    }

    public function test_pages_require_their_permissions(): void
    {
        $customer = Customer::factory()->create();
        $viewer = $this->userWith(Permission::CustomersView);

        $this->actingAs($viewer)->get('/customers')->assertOk();
        $this->actingAs($viewer)->get("/customers/{$customer->customer_no}")->assertOk();
        $this->actingAs($viewer)->get('/customers/create')->assertForbidden();
        $this->actingAs($viewer)->get("/customers/{$customer->customer_no}/edit")->assertForbidden();
        $this->actingAs($viewer)->delete("/customers/{$customer->customer_no}")->assertForbidden();

        $this->actingAs($this->userWith(Permission::CustomersCreate))->get('/customers')->assertForbidden();
    }

    public function test_customer_is_created_and_redirected_to_its_page(): void
    {
        $this->actingAs($this->userWith(Permission::CustomersCreate, Permission::CustomersView))
            ->post('/customers', ['name' => 'New Person', 'mobile' => '01710000000'])
            ->assertSessionHas('success')
            ->assertRedirect();

        $customer = Customer::firstOrFail();
        $this->assertSame('New Person', $customer->name);
    }

    public function test_validation_messages_are_returned_to_the_form(): void
    {
        $this->actingAs($this->userWith(Permission::CustomersCreate))
            ->from('/customers/create')
            ->post('/customers', ['name' => '', 'mobile' => 'abc'])
            ->assertRedirect('/customers/create')
            ->assertSessionHasErrors(['name', 'mobile']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_edit_form_updates_through_a_multipart_method_spoofed_post(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->create();
        $user = $this->userWith(Permission::CustomersUpdate, Permission::CustomersView);

        $this->actingAs($user)->get("/customers/{$customer->customer_no}/edit")
            ->assertInertia(fn (Assert $page) => $page->component('customers/edit')->where('customer.customer_no', $customer->customer_no));

        $this->actingAs($user)
            ->post("/customers/{$customer->customer_no}", [
                '_method' => 'put', 'name' => 'Renamed', 'mobile' => $customer->mobile, 'nid' => '', 'address' => '',
                'remove_image' => '0', 'image' => UploadedFile::fake()->image('p.jpg', 100, 100),
            ])
            ->assertRedirect("/customers/{$customer->customer_no}")
            ->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('Renamed', $customer->name);
        $this->assertNull($customer->nid);
        Storage::disk('local')->assertExists($customer->image_path);

        // The web page links the session-authenticated photo URL.
        $this->actingAs($user)->get("/customers/{$customer->customer_no}")
            ->assertInertia(fn (Assert $page) => $page->where('customer.image_url', route('customers.image', $customer)));
        $this->actingAs($user)->get(route('customers.image', $customer))->assertOk();
    }

    public function test_detail_shows_summary_and_open_loans_only_to_users_who_may_view_loans(): void
    {
        $customer = Customer::factory()->create();
        $this->loan($customer, 'LN-1', 'active');
        $this->loan($customer, 'LN-2', 'closed');

        $this->actingAs($this->userWith(Permission::CustomersView, Permission::LoansView))
            ->get("/customers/{$customer->customer_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/show')
                ->where('customer.summary.active_loans', 1)
                ->has('activeLoans', 1)
                ->where('activeLoans.0.loan_no', 'LN-1')
                ->where('activeLoans.0.outstanding_principal', '40000.00')
                ->where('activeLoans.0.next_due_date', '2026-10-31'));

        $this->actingAs($this->userWith(Permission::CustomersView))
            ->get("/customers/{$customer->customer_no}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer.summary.active_loans', 1)
                ->where('activeLoans', null));
    }

    public function test_archive_and_restore_redirect_back_with_a_message(): void
    {
        $customer = Customer::factory()->create();
        $user = $this->userWith(Permission::CustomersArchive, Permission::CustomersView);

        $this->actingAs($user)->from('/customers')->delete("/customers/{$customer->customer_no}")
            ->assertRedirect('/customers')
            ->assertSessionHas('success', "Customer {$customer->customer_no} archived.");
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'archived']);

        $this->actingAs($user)->from('/customers')->post("/customers/{$customer->customer_no}/restore")
            ->assertSessionHas('success', "Customer {$customer->customer_no} restored.");
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }
}
