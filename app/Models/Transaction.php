<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\SaleCalculator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'invoice_no', 'client_token', 'customer_id', 'user_id', 'shift_id', 'subtotal', 'discount',
        'tax', 'tax_rate', 'total', 'cash_tendered', 'change_amount', 'points_earned', 'status', 'notes', 'voided_at', 'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'tax' => 'integer',
            'tax_rate' => 'decimal:2',
            'total' => 'integer',
            'cash_tendered' => 'integer',
            'change_amount' => 'integer',
            'points_earned' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Tarif PPN transaksi ini, siap tampil di struk — mis. "11" atau "11.5".
     *
     * Diambil dari kolom SNAPSHOT, bukan dari pengaturan sekarang. Kalau admin
     * mengubah PPN minggu depan, struk lama harus tetap menampilkan tarif yang
     * benar-benar dipakai saat itu, bukan tarif yang baru.
     *
     * Return type HARUS Attribute, dan namanya taxRateLabel (tanpa akhiran
     * "Attribute"). HasAttributes::hasAttributeMutator memanggil
     * Str::camel($key) apa adanya — bukan getTaxRateLabelAttribute maupun
     * taxRateLabelAttribute — jadi nama yang salah membuat accessor DIABAIKAN
     * diam-diam dan mengembalikan null, tanpa error sama sekali.
     */
    protected function taxRateLabel(): Attribute
    {
        return Attribute::get(
            fn (): string => SaleCalculator::formatRate((float) $this->tax_rate),
        );
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<TransactionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * @return HasMany<TransactionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class);
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }
}
