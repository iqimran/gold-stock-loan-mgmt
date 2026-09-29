<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates role create (POST) and update (PUT/PATCH) submissions.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? $this->user()->can('update', $role)
            : $this->user()->can('create', Role::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', 'web')
                    ->ignore($this->route('role')),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::names())],
        ];
    }
}
