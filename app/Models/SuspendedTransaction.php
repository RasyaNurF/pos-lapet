<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspendedTransaction extends Model
{
    protected $fillable = [
        'user_id', 'customer_id', 'cart', 'discount', 'label',
    ];

    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'discount' => 'integer',
        ];
    }

    /**
     * `withTrashed()` supaya kasir yang sudah dihapus tetap tampil namanya
     * di riwayat shift, transaksi, dan audit. Tanpa ini, begitu akun dihapus
     * seluruh sejarah penjualan langsung kehilangan identitas pelakunya.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
