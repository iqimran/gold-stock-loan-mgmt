<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogSearch;
use App\Domain\Audit\AuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogSearchRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration → Audit log (audit.view). Read-only: entries are immutable.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogSearchRequest $request, AuditLogSearch $search): Response
    {
        $filters = $request->filters();

        return Inertia::render('admin/audit-logs/index', [
            'logs' => AuditLogResource::collection($search->paginate($filters, $request->integer('per_page', 25))),
            'filters' => array_map(fn ($value) => $value === null ? '' : $value, [...$filters, 'user' => $filters['user'] !== null ? (string) $filters['user'] : null]),
            'events' => AuditTrail::EVENTS,
            'areas' => AuditLogSearchRequest::AREAS,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
        ]);
    }
}
