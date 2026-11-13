<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Akses pengaturan toko.
 *
 * Dipakai sebagai SINGLETON (lihat AppServiceProvider) supaya seluruh request
 * berbagi satu cache in-memory. Tanpa ini, pemanggilan di dalam loop Blade
 * akan mengirim satu query per baris.
 */
class SettingService
{
    private const string CACHE_KEY = 'settings.all';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * Semua pengaturan, sudah dicampur dengan default dari registry.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        /** @var array<string, array{group: string, label: string, default: mixed, rules: array<int, string>}> $items */
        $items = config('settings.items', []);

        $values = [];
        foreach ($items as $key => $config) {
            $values[$key] = $config['default'];
        }

        foreach ($this->stored() as $key => $value) {
            if (array_key_exists($key, $items)) {
                $values[$key] = $this->cast($items[$key]['default'], $value);
            }
        }

        return $this->cache = $values;
    }

    /**
     * Ambil satu pengaturan.
     *
     * Memakai array_key_exists, BUKAN ??: nilai yang memang null (bisa saja
     * terisi manual lewat query) harus terbaca sebagai null, bukan jatuh ke
     * default seolah belum pernah diatur.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->all();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /**
     * Simpan sebagian pengaturan. Key yang tidak dikenal registry diabaikan,
     * sehingga form tidak bisa menyuntikkan kolom sembarangan.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function set(array $data): void
    {
        /** @var array<string, array{group: string, label: string, default: mixed, rules: array<int, string>}> $items */
        $items = config('settings.items', []);
        $rows = [];

        foreach ($items as $key => $config) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            /*
             * Key memuat titik (company.name), dan validator Laravel
             * menafsirkan titik sebagai akses array bersarang. Karena itu
             * divalidasi di bawah nama netral 'value', lalu label diganti
             * dengan nama pengaturan agar pesan error tetap terbaca.
             */
            $validator = Validator::make(
                ['value' => $data[$key]],
                ['value' => $config['rules']],
            );
            $validator->setAttributeNames(['value' => $config['label']]);

            if ($validator->fails()) {
                throw ValidationException::withMessages([
                    $key => $validator->errors()->first(),
                ]);
            }

            $rows[$key] = $this->normalize($config['default'], $data[$key]);
        }

        if ($rows === []) {
            return;
        }

        // Pastikan simbol baru memiliki kurs sah sebelum pengaturan disimpan.
        if (isset($rows['currency_symbol'])) {
            $currency = config('currencies')[$rows['currency_symbol']]['code'];
            try {
                app(ExchangeRateService::class)->quote($currency);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['currency_symbol' => $e->getMessage()]);
            }
            if ($rows['currency_symbol'] !== $this->get('currency_symbol') && ! isset($rows['currency_decimals'])) {
                $rows['currency_decimals'] = (string) config('currencies')[$rows['currency_symbol']]['decimals'];
            }
        }

        DB::transaction(function () use ($rows): void {
            /*
             * Kosong tetap disimpan '' — BUKAN NULL. Kalau NULL, "kosong
             * disengaja" (mis. tanpa pemisah ribuan) tidak bisa dibedakan
             * dari "belum pernah diisi", dan pembaca akan salah jatuh ke
             * default.
             */
            Setting::upsert(
                collect($rows)
                    ->map(fn (mixed $value, string $key): array => [
                        'key' => $key,
                        'value' => $value,
                    ])
                    ->values()
                    ->all(),
                ['key'],
                ['value'],
            );
        });

        $this->flush();
    }

    /**
     * Kembalikan satu pengaturan ke nilai default bawaan.
     */
    public function reset(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush();
    }

    /**
     * Buang cache — dipanggil setelah tulis, dan oleh test.
     */
    public function flush(): void
    {
        $this->cache = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHours(12),
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * Susun nilai sesuai tipe default, supaya tersimpan konsisten di DB.
     *
     * String kosong TIDAK dikonversi ke NULL — lihat penjelasan di set().
     */
    private function normalize(mixed $default, mixed $value): string
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '';
        }

        if (is_int($default)) {
            return (string) (int) $value;
        }

        if (is_float($default)) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    /**
     * Kembalikan nilai tersimpan ke tipe aslinya berdasarkan tipe default.
     */
    private function cast(mixed $default, ?string $value): mixed
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        if (is_int($default)) {
            return (int) $value;
        }

        if (is_float($default)) {
            return (float) $value;
        }

        return $value;
    }
}
