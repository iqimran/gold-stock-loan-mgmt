<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_home_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_public_self_registration_is_disabled(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }

    public function test_models_are_strict_outside_production(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_users_table_has_status_and_audit_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['is_active', 'last_login_at', 'created_by', 'updated_by']));
    }

    public function test_userstamps_are_filled_from_the_authenticated_user(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $user = User::factory()->create();
        $this->assertSame($actor->id, $user->created_by);
        $this->assertSame($actor->id, $user->updated_by);

        $editor = User::factory()->create();
        $this->actingAs($editor);
        $user->update(['name' => 'Renamed']);

        $this->assertSame($actor->id, $user->fresh()->created_by);
        $this->assertSame($editor->id, $user->fresh()->updated_by);
        $this->assertTrue($user->creator->is($actor));
    }

    public function test_userstamps_are_null_without_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->created_by);
        $this->assertNull($user->updated_by);
    }
}
