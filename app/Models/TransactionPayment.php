<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionPayment extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_QRIS = 'qris';

    public const METHOD_BANK = 'bank';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_DANA = 'dana';

    public const METHOD_GOPAY = 'gopay';

    /** @var array<string, string> */
    public const METHODS = [
        self::METHOD_CASH => 'Tunai',
        self::METHOD_QRIS => 'QRIS',
        self::METHOD_BANK => 'Transfer Bank',
        self::METHOD_CREDIT_CARD => 'Kartu Kredit',
        self::METHOD_DANA => 'DANA',
        self::METHOD_GOPAY => 'GoPay',
    ];

    protected $fillable = [
        'transaction_id', 'method', 'amount', 'amount_tendered', 'change_amount', 'reference_no',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_tendered' => 'integer',
            'change_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
