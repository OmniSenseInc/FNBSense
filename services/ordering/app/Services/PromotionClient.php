<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CatalogUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PromotionClient
{
    /**
     * @param  array{
     *   gross_subtotal:int,
     *   items:array<int,array{product_id:string,unit_price:int,qty:int,line_total:int}>
     * }  $calculation
     * @return array{discount_total:int,promotion:array<string,mixed>|null}
     */
    public function evaluate(
        string $tenantId,
        string $outletId,
        array $calculation,
    ): array {
        $serviceToken = (string) config('services.catalog.internal_token');
        if ($serviceToken === '') {
            throw new CatalogUnavailableException('Service token Catalog belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeader('X-Service-Token', $serviceToken)
                ->post('/api/internal/promotions/evaluate', [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'subtotal' => $calculation['gross_subtotal'],
                    'items' => collect($calculation['items'])->map(fn (array $item) => [
                        'product_id' => $item['product_id'],
                        'unit_price' => $item['unit_price'],
                        'qty' => $item['qty'],
                        'line_total' => $item['line_total'],
                    ])->all(),
                ]);
        } catch (ConnectionException $exception) {
            throw new CatalogUnavailableException(
                'Catalog tidak dapat mengevaluasi promo.',
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            throw new CatalogUnavailableException(
                "Evaluasi promo membalas status {$response->status()}."
            );
        }

        $data = $response->json('data');
        $discount = is_array($data) ? ($data['discount_total'] ?? null) : null;
        $promotion = is_array($data) ? ($data['promotion'] ?? null) : null;

        if (! is_int($discount)
            || $discount < 0
            || $discount > $calculation['gross_subtotal']
            || ($promotion !== null && ! is_array($promotion))
            || ($discount > 0 && (! is_array($promotion)
                || ! is_string($promotion['id'] ?? null)
                || ! is_string($promotion['name'] ?? null)
                || ! is_string($promotion['template'] ?? null)))) {
            throw new CatalogUnavailableException('Respons evaluasi promo tidak valid.');
        }

        return ['discount_total' => $discount, 'promotion' => $promotion];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.catalog.base_url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.catalog.timeout', 3);
    }
}
