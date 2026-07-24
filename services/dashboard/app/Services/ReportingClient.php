<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportingClient
{
    public function summary(string $from, string $to): array
    {
        return $this->get('/api/summary', compact('from', 'to'));
    }

    public function dailyTrend(string $from, string $to): array
    {
        return $this->get('/api/trends/daily', compact('from', 'to'));
    }

    public function topProducts(string $from, string $to, int $limit = 10): array
    {
        return $this->get('/api/products/top', compact('from', 'to', 'limit'));
    }

    private function get(string $path, array $query): array
    {
        $token = session('dashboard.jwt');

        if (! is_string($token) || $token === '') {
            return $this->failure('Sesi API tidak tersedia.');
        }

        try {
            $response = Http::baseUrl((string) config('services.reporting.url'))
                ->acceptJson()
                ->withToken($token)
                ->timeout(8)
                ->get($path, $query)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('dashboard.reporting: API tidak tersedia.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return $this->failure('Reporting sedang tidak tersedia.');
        }

        $data = $response->json('data');

        return is_array($data)
            ? ['ok' => true, 'data' => $data, 'message' => null]
            : $this->failure('Respons Reporting tidak valid.');
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'data' => [], 'message' => $message];
    }
}
