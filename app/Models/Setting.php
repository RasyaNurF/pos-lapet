<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris per pengaturan. Key memakai titik (company.name).
 *
 * Bukan tabel bisnis — nilainya boleh ditimpa bebas oleh admin, jadi tidak
 * memerlukan soft delete (ADR-0026 hanya berlaku untuk tabel bisnis).
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key', 'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'string',
        ];
    }
}
