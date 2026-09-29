<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alert\AlertQuery;
use App\Domain\Alert\AlertSettings;
use App\Enums\AlertStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Missed-interest alerts (read-only: alerts are raised and resolved by the interest run and payments).
 * Visible to users who may view loans.
 */
class AlertController extends Controller
{
    public function index(Request $request, AlertQuery $alerts): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Loan::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...AlertStatus::values(), 'all'])],
            'customer' => ['nullable', 'string', 'max:30'],
            'loan' => ['nullable', 'string', 'max:30'],
            'triggered_from' => ['nullable', 'date_format:Y-m-d'],
            'triggered_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:triggered_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AlertResource::collection($alerts->paginate($filters, $request->integer('per_page', 20)));
    }

    /**
     * GET /dashboard/missed-payment-alerts (docs/04): the dashboard card.
     */
    public function summary(AlertQuery $alerts): JsonResponse
    {
        Gate::authorize('viewAny', Loan::class);

        return response()->json(['data' => $alerts->summary() + ['threshold' => app(AlertSettings::class)->missedPeriodThreshold]]);
    }
}
