<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PromotionTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'template' => ['required', Rule::enum(PromotionTemplate::class)],
            'percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'min_subtotal' => ['sometimes', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'products' => ['nullable', 'array', 'max:50'],
            'products.*.product_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'products.*.required_qty' => ['sometimes', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $template = $this->input('template');
                $products = $this->input('products', []);

                if (in_array($template, [
                    PromotionTemplate::OrderPercentage->value,
                    PromotionTemplate::ProductPercentage->value,
                ], true) && $this->input('percentage') === null) {
                    $validator->errors()->add('percentage', 'Persentase wajib untuk template ini.');
                }

                if (in_array($template, [
                    PromotionTemplate::OrderFixed->value,
                    PromotionTemplate::BundleFixedPrice->value,
                ], true) && $this->input('amount') === null) {
                    $validator->errors()->add('amount', 'Nominal wajib untuk template ini.');
                }

                if ($template === PromotionTemplate::OrderFixed->value
                    && (int) $this->input('amount', 0) < 1) {
                    $validator->errors()->add('amount', 'Potongan nominal minimal Rp1.');
                }

                if ($template === PromotionTemplate::ProductPercentage->value
                    && (! is_array($products) || count($products) < 1)) {
                    $validator->errors()->add('products', 'Pilih minimal satu produk.');
                }

                if ($template === PromotionTemplate::BundleFixedPrice->value
                    && (! is_array($products) || count($products) < 2)) {
                    $validator->errors()->add('products', 'Bundle membutuhkan minimal dua produk.');
                }

                if (in_array($template, [
                    PromotionTemplate::OrderPercentage->value,
                    PromotionTemplate::OrderFixed->value,
                ], true) && is_array($products) && $products !== []) {
                    $validator->errors()->add('products', 'Template order tidak memakai target produk.');
                }
            },
        ];
    }
}
