<?php

namespace App\Actions\Customers;

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
 * New customers get a generated customer number (CUS-YYYYMM-000001) and start active; status is
 * changed only through ChangeCustomerStatus (customers.archive). A replaced or removed photo is
 * deleted after commit, so a rolled-back update never loses the current file.
 */
class SaveCustomer
{
    public const NUMBER_PREFIX = 'CUS';

    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

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
                    'customer_no' => $this->numbers->next(self::NUMBER_PREFIX),
                    'status' => CustomerStatus::Active,
                ]);

                $previousImage = $customer->image_path;

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
}
