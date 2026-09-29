<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates customer create (POST) and update (PUT/PATCH) submissions.
 *
 * Status is not accepted here: archiving/restoring needs customers.archive (ChangeCustomerStatus).
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'address'] as $field) {
            if ($this->has($field)) {
                $normalized[$field] = trim((string) $this->input($field)) ?: null;
            }
        }

        // Stored without spaces/dashes so exact-match search works however the number was typed.
        if ($this->has('mobile')) {
            $normalized['mobile'] = preg_replace('/[\s\-]/', '', (string) $this->input('mobile'));
        }

        if ($this->has('nid')) {
            $normalized['nid'] = strtoupper(preg_replace('/\s/', '', (string) $this->input('nid'))) ?: null;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'nid' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/'],
            'address' => ['nullable', 'string', 'max:500'],
            // Raster images only: an uploaded SVG could carry script and is served from this origin.
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile.regex' => 'The mobile number may contain only digits (6–20), optionally starting with +.',
            'nid.regex' => 'The NID may contain only letters, digits and dashes.',
            'image.mimes' => 'The image must be a PNG, JPG or WebP image.',
            'image.mimetypes' => 'The image must be a PNG, JPG or WebP image.',
            'image.max' => 'The image may not be larger than 2 MB.',
            'image.dimensions' => 'The image must be between 32×32 and 4000×4000 pixels.',
        ];
    }

    /**
     * @return array{name: string, mobile: string, nid: ?string, address: ?string}
     */
    public function details(): array
    {
        return $this->safe()->only(['name', 'mobile', 'nid', 'address']) + ['nid' => null, 'address' => null];
    }
}
