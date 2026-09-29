<?php

namespace App\Actions\Customers;

use App\Domain\Audit\AuditTrail;
use App\Domain\Settings\LoanSettings;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\DocumentNumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates or updates a customer, including the optional photo.
 *
 * New customers get a generated customer number (format: Settings → numbering) and start active; status is
 * changed only through ChangeCustomerStatus (customers.archive). A replaced or removed photo is
 * deleted after commit, so a rolled-back update never loses the current file.
 *
 * Audited (customer.created / customer.updated, changed fields only). The NID is personal data: the
 * audit keeps only a masked form (last 4 digits), enough to see that and how it changed; the photo is
 * recorded as present / replaced / removed, never its path.
 */
class SaveCustomer
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly LoanSettings $settings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{name: string, mobile: string, nid?: ?string, address?: ?string}  $data
     */
    public function handle(?Customer $customer, array $data, ?UploadedFile $image = null, bool $removeImage = false): Customer
    {
        $newImage = $image?->storeAs(
            Customer::IMAGE_DIRECTORY,
            Str::random(32).'.'.$image->extension(),
            Customer::IMAGE_DISK,
        );

        try {
            return DB::transaction(function () use ($customer, $data, $newImage, $removeImage): Customer {
                $customer ??= new Customer([
                    'customer_no' => $this->numbers->nextIn($this->settings->numbering('customer')),
                    'status' => CustomerStatus::Active,
                ]);

                $previousImage = $customer->image_path;
                $isNew = ! $customer->exists;
                $before = $isNew ? [] : $this->auditable($customer);

                $customer->fill([
                    'name' => $data['name'],
                    'mobile' => $data['mobile'],
                    'nid' => $data['nid'] ?? null,
                    'address' => $data['address'] ?? null,
                ]);

                if ($newImage !== null || $removeImage) {
                    $customer->image_path = $newImage;
                }

                $customer->save();

                $after = $this->auditable($customer);

                if ($previousImage !== null && $customer->image_path !== null && $previousImage !== $customer->image_path) {
                    $after['photo'] = 'replaced';
                }

                $isNew
                    ? $this->audit->record('customer.created', $customer, [], ['customer_no' => $customer->customer_no, 'status' => $customer->status->value, ...$after], "Customer {$customer->customer_no} created")
                    : $this->audit->recordChanges('customer.updated', $customer, $before, $after, "Customer {$customer->customer_no} updated");

                if ($previousImage !== null && $previousImage !== $customer->image_path) {
                    DB::afterCommit(fn () => Storage::disk(Customer::IMAGE_DISK)->delete($previousImage));
                }

                return $customer;
            });
        } catch (Throwable $e) {
            if ($newImage !== null) {
                Storage::disk(Customer::IMAGE_DISK)->delete($newImage);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function auditable(Customer $customer): array
    {
        $nid = $customer->nid;

        return [
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'nid' => $nid === null || $nid === '' ? null : str_repeat('•', max(strlen($nid) - 4, 0)).substr($nid, -4),
            'address' => $customer->address,
            'photo' => $customer->image_path !== null ? 'present' : 'none',
        ];
    }
}
