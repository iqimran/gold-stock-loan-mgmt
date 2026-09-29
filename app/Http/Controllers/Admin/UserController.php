<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ChangeUserStatus;
use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserStatusRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/index', [
            'users' => UserResource::collection($users),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/create', $this->formOptions());
    }

    public function store(UserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $user = $createUser->handle($request->validated());

        return to_route('admin.users.index')->with('success', "User {$user->name} created.");
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => new UserResource($user->load(['roles', 'permissions'])),
            ...$this->formOptions(),
        ]);
    }

    public function update(UserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $updateUser->handle($request->user(), $user, $request->validated());

        return to_route('admin.users.index')->with('success', "User {$user->name} updated.");
    }

    public function updateStatus(ChangeUserStatusRequest $request, User $user, ChangeUserStatus $changeUserStatus): RedirectResponse
    {
        $changeUserStatus->handle($request->user(), $user, $request->boolean('is_active'));

        $state = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User {$user->name} {$state}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'permissionGroups' => Permission::grouped(),
        ];
    }
}
