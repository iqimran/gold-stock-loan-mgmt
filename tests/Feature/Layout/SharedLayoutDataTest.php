<?php

namespace Tests\Feature\Layout;

use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedLayoutDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_user_receives_only_their_permissions_for_navigation()
    {
        $user = $this->generalUser();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('name', config('app.name'))
                ->where('auth.user.id', $user->id)
                ->where('auth.user.roles', ['General User'])
                ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all()
                    === collect(Permission::generalUserDefaults())->pluck('value')->sort()->values()->all())
                ->missing('auth.user.password'));
    }

    public function test_admin_receives_every_permission_for_navigation()
    {
        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.roles', ['Admin'])
                ->where('auth.user.permissions', Permission::names()));
    }

    public function test_flash_messages_are_shared()
    {
        $this->actingAs($this->admin())
            ->withSession(['success' => 'Saved!', 'error' => 'Oops'])
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Saved!')
                ->where('flash.error', 'Oops'));
    }

    public function test_guest_pages_share_a_null_user()
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('auth.user', null));
    }

    public function test_missing_pages_render_the_error_page()
    {
        $this->actingAs($this->generalUser())->get('/does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
    }

    public function test_forbidden_actions_render_the_error_page()
    {
        $this->actingAs($this->generalUser())->get('/admin/roles')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
    }

    public function test_api_errors_stay_json()
    {
        $this->actingAs($this->generalUser())->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_expired_page_redirects_back_with_flash_message()
    {
        Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException);

        $this->actingAs($this->generalUser())
            ->from('/dashboard')
            ->post('/_test/expired')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error', 'The page expired. Please try again.');
    }
}
