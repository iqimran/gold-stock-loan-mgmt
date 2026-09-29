<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loan customer. Authorized by App\Policies\CustomerPolicy.
 *
 * Archived (status), never hard-deleted: loans, payments and ledger entries restrict deletion.
 * Addressed publicly by customer_no rather than the internal id (docs/02-architecture.md "Security").
 * Derived figures (interest due, missed periods, …) are computed by App\Domain\Customer\CustomerSummary.
 *
 * @property int $id
 * @property string $customer_no
 * @property string $name
 * @property string $mobile
 * @property ?string $nid
 * @property ?string $image_path
 * @property ?string $address
 * @property CustomerStatus $status
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUserstamps;

    /** Customer photos live on the private disk and are served by an authorized endpoint. */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'customers';

    protected $fillable = ['customer_no', 'name', 'mobile', 'nid', 'image_path', 'address', 'status'];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'customer_no';
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isArchived(): bool
    {
        return $this->status === CustomerStatus::Archived;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', CustomerStatus::Active->value);
    }
}
