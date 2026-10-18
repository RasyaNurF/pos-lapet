<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Layer 5 Operational Guard — pencatat jejak audit.
 *
 * Semua operasi sensitif (CRUD master, penyesuaian stok, void, shift) wajib
 * melewati service ini sehingga tersimpan: siapa, apa, nilai lama → baru, dari IP mana.
 *
 * Dirancang null-safe: bila konteks non-HTTP (queue/artisan) atau user null,
 * log tetap dibuat dengan user_id null, dan kegagalan menulis audit tidak
 * pernah menjatuhkan transaksi bisnis utama.
 */
class AuditLogger
{
    /**
     * Tulis satu baris audit.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null,
    ): ?AuditLog {
        $user ??= Auth::user();

        try {
            return AuditLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'auditable_type' => $model?->getMorphClass(),
                'auditable_id' => $model?->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => $this->limitAgent(request()?->userAgent()),
            ]);
        } catch (\Throwable $e) {
            // Audit tidak boleh menggagalkan operasi bisnis — laporkan ke log aplikasi.
            Log::error('Gagal menulis audit log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Buat ringkasan perubahan atribut model (hanya kolom yang benar-benar berubah).
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function changes(Model $model, array $original = []): array
    {
        $before = $original !== [] ? $original : $model->getOriginal();
        $after = $model->getAttributes();

        $old = [];
        $new = [];

        $hidden = $model->getHidden();

        foreach ($after as $key => $value) {
            if (in_array($key, $hidden, true) || $model->isRelation($key)) {
                continue;
            }

            if (! array_key_exists($key, $before) || $before[$key] === $value) {
                continue;
            }

            $old[$key] = $before[$key];
            $new[$key] = $value;
        }

        return ['old' => $old, 'new' => $new];
    }

    /**
     * Pangkas user-agent agar muat kolom (191 char) tanpa memotong di tengah byte UTF-8.
     */
    private function limitAgent(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > 191 ? mb_substr($value, 0, 188).'...' : $value;
    }
}
