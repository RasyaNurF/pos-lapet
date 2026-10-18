<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'user_id', 'start_time', 'end_time', 'start_cash', 'expected_cash',
        'end_cash', 'difference', 'total_sales',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'start_cash' => 'integer',
            'expected_cash' => 'integer',
            'end_cash' => 'integer',
            // `difference` SIGNED — boleh negatif (kas kurang).
            'difference' => 'integer',
            'total_sales' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isOpen(): bool
    {
        return $this->end_time === null;
    }

    /**
     * Shift aktif (belum ditutup) milik user tertentu.
     */
    public static function openFor(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();
    }
}
