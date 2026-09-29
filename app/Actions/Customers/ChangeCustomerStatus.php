<?php

namespace App\Actions\Customers;

use App\Domain\Audit\AuditTrail;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Archives or restores a customer. Customers are never hard-deleted: their loans, payments and
 * ledger history stay intact and reportable (docs/08 "Customer deletion").
 */
class ChangeCustomerStatus
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Customer $customer, CustomerStatus $status): Customer
    {
        return DB::transaction(function () use ($customer, $status): Customer {
            $from = $customer->status;
            $customer->update(['status' => $status]);

            if ($from !== $status) {
                $this->audit->record($status === CustomerStatus::Archived ? 'customer.archived' : 'customer.restored', $customer,
                    ['status' => $from->value], ['status' => $status->value], "Customer {$customer->customer_no} {$status->value}");
            }

            return $customer;
        });
    }
}
