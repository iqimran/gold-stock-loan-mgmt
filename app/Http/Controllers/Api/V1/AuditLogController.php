<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogSearchRequest;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/audit-logs (audit.view): same filters as the Audit log screen.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogSearchRequest $request, AuditLogSearch $search): AnonymousResourceCollection
    {
        return AuditLogResource::collection($search->paginate($request->filters(), $request->integer('per_page', 25)));
    }
}
