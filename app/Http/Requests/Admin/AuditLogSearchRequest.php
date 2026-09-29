<?php

namespace App\Http\Requests\Admin;

use App\Domain\Audit\AuditLogSearch;
use App\Domain\Audit\AuditTrail;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Audit log filters (web and API). Viewing the audit log needs audit.view.
 */
class AuditLogSearchRequest extends FormRequest
{
    public const AREAS = ['loan', 'payment', 'collateral', 'customer', 'settings', 'user', 'role', 'auth'];

    public function authorize(): bool
    {
        return Gate::allows(Permission::AuditView->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(array_keys(AuditTrail::EVENTS))],
            'area' => ['nullable', Rule::in(self::AREAS)],
            'entity' => ['nullable', Rule::in(array_keys(AuditLogSearch::ENTITIES))],
            'reference' => ['nullable', 'string', 'max:30'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
            'system' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q: string, event: ?string, area: ?string, entity: ?string, reference: ?string, user: ?int, system: bool, from: ?string, to: ?string}
     */
    public function filters(): array
    {
        $v = $this->safe();

        return [
            'q' => (string) ($v['q'] ?? ''),
            'event' => $v['event'] ?? null,
            'area' => $v['area'] ?? null,
            'entity' => $v['entity'] ?? null,
            'reference' => filled($v['reference'] ?? null) ? trim($v['reference']) : null,
            'user' => isset($v['user']) ? (int) $v['user'] : null,
            'system' => $this->boolean('system'),
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
        ];
    }
}
