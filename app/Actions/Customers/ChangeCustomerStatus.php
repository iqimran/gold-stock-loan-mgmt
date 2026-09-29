<?php

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Models\Customer;

/**
 * Archives or restores a customer. Customers are never hard-deleted: their loans, payments and
 * ledger history stay intact and reportable (docs/08 "Customer deletion").
 */
class ChangeCustomerStatus
{
    public function handle(Customer $customer, CustomerStatus $status): Customer
    {
        $customer->update(['status' => $status]);

        return $customer;
    }
}
