<?php

namespace Tests\Feature\Customers;

use App\Enums\CustomerStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));
        Sanctum::actingAs($user);

        return $user;
    }

    private function valid(array $overrides = []): array
    {
        return ['name' => 'Rahima Begum', 'mobile' => '01711-000 111', 'nid' => '1990 1234567', 'address' => 'Dhaka', ...$overrides];
    }

    // ── create ──────────────────────────────────────────────────────────────────────────────

    public function test_customer_can_be_created_with_a_generated_number(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        $user = $this->actingWith(Permission::CustomersCreate);

        $this->postJson('/api/v1/customers', $this->valid())
            ->assertCreated()
            ->assertJsonPath('data.customer_no', 'CUS-202610-000001')
            ->assertJsonPath('data.name', 'Rahima Begum')
            ->assertJsonPath('data.mobile', '01711000111')
            ->assertJsonPath('data.nid', '19901234567')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.summary.active_loans', 0)
            ->assertJsonPath('data.summary.total_interest_due', '0.00')
            ->assertJsonMissingPath('data.id');

        $this->postJson('/api/v1/customers', $this->valid())->assertJsonPath('data.customer_no', 'CUS-202610-000002');

        $this->assertDatabaseHas('customers', ['customer_no' => 'CUS-202610-000001', 'created_by' => $user->id, 'updated_by' => $user->id]);
    }

    public function test_create_is_validated_server_side(): void
    {
        $this->actingWith(Permission::CustomersCreate);

        $this->postJson('/api/v1/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'mobile']);

        $this->postJson('/api/v1/customers', $this->valid(['mobile' => '01711abc', 'nid' => 'NID#1', 'name' => str_repeat('x', 151)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile', 'nid', 'name']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_status_cannot_be_set_through_create_or_update(): void
    {
        $this->actingWith(Permission::CustomersCreate, Permission::CustomersUpdate);

        $customerNo = $this->postJson('/api/v1/customers', $this->valid(['status' => 'archived']))
            ->assertJsonPath('data.status', 'active')
            ->json('data.customer_no');

        $this->patchJson("/api/v1/customers/{$customerNo}", $this->valid(['status' => 'archived']))
            ->assertJsonPath('data.status', 'active');
    }

    // ── photo (private disk, raster only) ────────────────────────────────────────────────────

    public function test_photo_is_stored_privately_and_served_to_authorized_users(): void
    {
        Storage::fake('local');
        $this->actingWith(Permission::CustomersCreate, Permission::CustomersView);

        $response = $this->post('/api/v1/customers', $this->valid(['image' => UploadedFile::fake()->image('photo.jpg', 200, 200)]), ['Accept' => 'application/json'])
            ->assertCreated();

        $customer = Customer::firstOrFail();
        $this->assertStringStartsWith('customers/', $customer->image_path);
        Storage::disk('local')->assertExists($customer->image_path);
        $response->assertJsonPath('data.image_url', route('api.v1.customers.image', $customer));

        $this->get("/api/v1/customers/{$customer->customer_no}/image")->assertOk();

        $this->actingWith(Permission::CustomersCreate);
        $this->getJson("/api/v1/customers/{$customer->customer_no}/image")->assertForbidden();
    }

    public function test_non_raster_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $this->actingWith(Permission::CustomersCreate);

        $svg = UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->post('/api/v1/customers', $this->valid(['image' => $svg]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacing_or_removing_the_photo_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $this->actingWith(Permission::CustomersCreate, Permission::CustomersUpdate);

        $this->post('/api/v1/customers', $this->valid(['image' => UploadedFile::fake()->image('a.jpg', 100, 100)]), ['Accept' => 'application/json']);
        $customer = Customer::firstOrFail();
        $first = $customer->image_path;

        $this->post("/api/v1/customers/{$customer->customer_no}", [...$this->valid(), '_method' => 'PATCH', 'image' => UploadedFile::fake()->image('b.png', 100, 100)], ['Accept' => 'application/json'])
            ->assertOk();

        $second = $customer->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->patchJson("/api/v1/customers/{$customer->customer_no}", $this->valid(['remove_image' => true]))
            ->assertOk()
            ->assertJsonPath('data.image_url', null);
        Storage::disk('local')->assertMissing($second);
    }

    // ── view / update ────────────────────────────────────────────────────────────────────────

    public function test_customer_is_shown_by_customer_number_with_summary(): void
    {
        $this->actingWith(Permission::CustomersView);
        $customer = Customer::factory()->create();

        $this->getJson("/api/v1/customers/{$customer->customer_no}")
            ->assertOk()
            ->assertJsonPath('data.customer_no', $customer->customer_no)
            ->assertJsonStructure(['data' => ['summary' => ['active_loans', 'total_interest_due', 'consecutive_missed', 'next_due_date', 'last_payment']]]);

        // Internal ids are not route keys.
        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound();
    }

    public function test_customer_can_be_updated_but_keeps_its_number(): void
    {
        $user = $this->actingWith(Permission::CustomersUpdate);
        $customer = Customer::factory()->create();

        $this->putJson("/api/v1/customers/{$customer->customer_no}", $this->valid(['name' => 'Updated Name', 'customer_no' => 'CUS-HACK', 'nid' => null]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.nid', null)
            ->assertJsonPath('data.customer_no', $customer->customer_no);

        $this->assertSame($user->id, $customer->fresh()->updated_by);
    }

    // ── archive / restore (never hard-deleted) ───────────────────────────────────────────────

    public function test_delete_archives_a_customer_with_financial_history_instead_of_deleting_it(): void
    {
        $this->actingWith(Permission::CustomersArchive);
        $customer = Customer::factory()->create();
        DB::table('loans')->insert([
            'loan_no' => 'L-1', 'customer_id' => $customer->id, 'principal' => '1000.00', 'outstanding_principal' => '1000.00', 'interest_rate' => '2.0000',
            'interest_rate_type' => 'percent', 'interest_period_unit' => 'month', 'status' => 'closed', 'start_date' => '2026-01-01',
        ]);

        $this->deleteJson("/api/v1/customers/{$customer->customer_no}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'archived']);
        $this->assertDatabaseHas('loans', ['customer_id' => $customer->id]);

        $this->postJson("/api/v1/customers/{$customer->customer_no}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_archive_and_restore_require_the_archive_permission(): void
    {
        $this->actingWith(Permission::CustomersView, Permission::CustomersCreate, Permission::CustomersUpdate);
        $customer = Customer::factory()->archived()->create();
        $active = Customer::factory()->create();

        $this->deleteJson("/api/v1/customers/{$active->customer_no}")->assertForbidden();
        $this->postJson("/api/v1/customers/{$customer->customer_no}/restore")->assertForbidden();
        $this->assertSame(CustomerStatus::Active, $active->fresh()->status);
    }

    // ── permissions ──────────────────────────────────────────────────────────────────────────

    public function test_each_endpoint_requires_its_permission(): void
    {
        $customer = Customer::factory()->create();
        $this->actingWith(Permission::CustomersView);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', $this->valid())->assertForbidden();
        $this->patchJson("/api/v1/customers/{$customer->customer_no}", $this->valid())->assertForbidden();

        $this->actingWith(Permission::CustomersCreate);
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->getJson("/api/v1/customers/{$customer->customer_no}")->assertForbidden();
    }

    public function test_guests_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    // ── search / filter / pagination ─────────────────────────────────────────────────────────

    public function test_list_is_paginated_and_shows_active_customers_by_default(): void
    {
        $this->actingWith(Permission::CustomersView);
        Customer::factory()->count(3)->create();
        Customer::factory()->archived()->create(['name' => 'Archived Person']);

        $this->getJson('/api/v1/customers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);

        $this->getJson('/api/v1/customers?status=archived')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Archived Person');
        $this->getJson('/api/v1/customers?status=all')->assertJsonPath('meta.total', 4);
    }

    public function test_search_matches_name_mobile_nid_and_number_case_insensitively(): void
    {
        $this->actingWith(Permission::CustomersView);
        $target = Customer::factory()->create(['name' => 'Karim Uddin', 'mobile' => '01812345678', 'nid' => 'AB12345']);
        Customer::factory()->create(['name' => 'Someone Else', 'mobile' => '01900000000', 'nid' => null]);

        foreach (['karim', 'UDDIN', '0181234', 'ab123', strtolower($target->customer_no)] as $term) {
            $this->getJson('/api/v1/customers?q='.urlencode($term))
                ->assertJsonPath('meta.total', 1, "Search [{$term}]")
                ->assertJsonPath('data.0.customer_no', $target->customer_no);
        }

        // LIKE wildcards in the term are literal.
        $this->getJson('/api/v1/customers?q=%25')->assertJsonPath('meta.total', 0);
    }

    public function test_exact_mobile_match_is_ranked_first(): void
    {
        $this->actingWith(Permission::CustomersView);
        Customer::factory()->create(['name' => 'Aaron', 'mobile' => '017111222339']);
        Customer::factory()->create(['name' => 'Zara', 'mobile' => '01711122233']);

        $this->getJson('/api/v1/customers?q=01711122233')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Zara');
    }

    public function test_registration_date_range_filter(): void
    {
        $this->actingWith(Permission::CustomersView);
        Customer::factory()->create(['name' => 'September', 'created_at' => '2026-09-10 12:00:00']);
        Customer::factory()->create(['name' => 'October', 'created_at' => '2026-10-10 12:00:00']);

        $this->getJson('/api/v1/customers?registered_from=2026-10-01&registered_to=2026-10-31')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'October');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingWith(Permission::CustomersView);

        $this->getJson('/api/v1/customers?status=deleted&min_missed=0&per_page=500&registered_from=2026-10-10&registered_to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'min_missed', 'per_page', 'registered_to']);
    }
}
