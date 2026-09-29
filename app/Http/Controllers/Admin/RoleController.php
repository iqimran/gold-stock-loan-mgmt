<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $role->isSystem(),
                'is_admin' => $role->isAdmin(),
                'users_count' => $role->users_count,
                'permissions_count' => $role->isAdmin() ? count(Permission::cases()) : $role->permissions_count,
            ]);

        return Inertia::render('admin/roles/index', ['roles' => $roles]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('admin/roles/create', [
            'permissionGroups' => Permission::grouped(),
        ]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $role = $saveRole->handle(null, $request->validated('name'), $request->validated('permissions'));

        return to_route('admin.roles.index')->with('success', "Role {$role->name} created.");
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('admin/roles/edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'is_system' => $role->isSystem(),
                'is_admin' => $role->isAdmin(),
                'permissions' => $role->isAdmin() ? Permission::names() : $role->permissions()->pluck('name'),
            ],
            'permissionGroups' => Permission::grouped(),
        ]);
    }

    public function update(RoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle($role, $request->validated('name'), $request->validated('permissions'));

        return to_route('admin.roles.index')->with('success', "Role {$role->name} updated.");
    }

    public function destroy(Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $deleteRole->handle($role);

        return to_route('admin.roles.index')->with('success', "Role {$role->name} deleted.");
    }
}
