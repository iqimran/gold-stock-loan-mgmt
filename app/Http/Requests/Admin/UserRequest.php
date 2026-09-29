<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates user create (POST) and update (PUT/PATCH) submissions.
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $creating = ! $user instanceof User;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::exists(config('permission.table_names.roles'), 'name')->where('guard_name', 'web')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::names())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
