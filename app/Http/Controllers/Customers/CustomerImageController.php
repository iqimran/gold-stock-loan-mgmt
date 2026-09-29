<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a customer photo from the private disk (never publicly linked). Used by the web and API routes.
 */
class CustomerImageController extends Controller
{
    public function __invoke(Customer $customer): StreamedResponse
    {
        Gate::authorize('view', $customer);

        $disk = Storage::disk(Customer::IMAGE_DISK);
        abort_unless($customer->image_path && $disk->exists($customer->image_path), 404);

        return $disk->response($customer->image_path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
