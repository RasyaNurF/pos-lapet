<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ExchangeRateService
{
    private array $quotes = [];

    private array $failed = [];

    /** Tarif adalah unit mata uang tujuan per 1 rupiah. */
    public function quote(string $currency): array
    {
        if ($currency === 'IDR') {
            return ['base' => 'IDR', 'quote' => 'IDR', 'rate' => 1.0, 'date' => now()->toDateString(), 'stale' => false];
        }

        if (! in_array($currency, array_column(config('currencies'), 'code'), true)) {
            throw new RuntimeException('Mata uang tidak didukung.');
        }

        if (isset($this->quotes[$currency])) {
            return $this->quotes[$currency];
        }
        if (isset($this->failed[$currency])) {
            throw new RuntimeException('Kurs belum tersedia. Periksa koneksi internet lalu coba lagi.');
        }

        $key = 'exchange-rate.IDR.'.$currency;
        $fresh = Cache::get($key);
        if (is_array($fresh)) {
            return $this->quotes[$currency] = $fresh;
        }

        try {
            $data = Http::acceptJson()->connectTimeout(3)->timeout(8)
                ->get('https://api.frankfurter.dev/v2/rate/IDR/'.$currency)
                ->throw()->json();

            if (! is_array($data) || ($data['base'] ?? null) !== 'IDR'
                || ($data['quote'] ?? null) !== $currency
                || ! is_numeric($data['rate'] ?? null) || ! is_finite((float) $data['rate'])
                || (float) $data['rate'] <= 0
                || ! is_string($data['date'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
                throw new RuntimeException('Respons kurs tidak valid.');
            }

            $quote = ['base' => 'IDR', 'quote' => $currency, 'rate' => (float) $data['rate'], 'date' => $data['date'], 'stale' => false];
            Cache::put($key, $quote, now()->addHour());
            Cache::put($key.'.last-good', $quote, now()->addDays(7));

            return $this->quotes[$currency] = $quote;
        } catch (Throwable $e) {
            $previous = Cache::get($key.'.last-good');
            if (is_array($previous)) {
                $previous['stale'] = true;
                // Hindari mengulang request gagal pada setiap nominal/halaman.
                Cache::put($key, $previous, now()->addMinutes(5));

                return $this->quotes[$currency] = $previous;
            }

            $this->failed[$currency] = true;
            throw new RuntimeException('Kurs belum tersedia. Periksa koneksi internet lalu coba lagi.', previous: $e);
        }
    }
}
