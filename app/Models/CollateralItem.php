<?php

namespace App\Models;

use App\Enums\CollateralStatus;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\CollateralItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gold/diamond item held against a loan. Authorized by App\Policies\CollateralItemPolicy; every change
 * goes through App\Domain\Collateral\CollateralService and is recorded in the loan's history.
 * Never deleted: a released item stays in history. Addressed publicly by collateral_no.
 *
 * @property int $id
 * @property string $collateral_no
 * @property int $loan_id
 * @property string $type
 * @property string $weight_grams
 * @property ?string $karat
 * @property string $estimated_value
 * @property ?string $description
 * @property CollateralStatus $status
 * @property Carbon $received_at
 * @property ?Carbon $released_at
 * @property ?int $released_by
 */
class CollateralItem extends Model
{
    /** @use HasFactory<CollateralItemFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'collateral_no', 'loan_id', 'type', 'weight_grams', 'karat', 'estimated_value', 'description',
        'status', 'received_at', 'released_at', 'released_by',
    ];

    protected function casts(): array
    {
        return [
            'weight_grams' => 'decimal:3',
            'karat' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'status' => CollateralStatus::class,
            'received_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'collateral_no';
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
