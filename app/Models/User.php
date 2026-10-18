<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password', 'role', 'shift_status',
    'phone', 'address', 'hire_date', 'is_active',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'hire_date' => 'date',
        ];
    }

    /**
     * Relasi shift aktif milik user.
     *
     * @return HasOne<Shift, $this>
     */
    public function currentShift(): HasOne
    {
        return $this->hasOne(Shift::class)->whereNull('end_time')->latest('start_time');
    }

    /**
     * Apakah user sedang membuka shift?
     */
    public function hasOpenShift(): bool
    {
        return $this->shift_status === 'open';
    }

    /**
     * Daftar role yang boleh dipilih admin pada form karyawan.
     *
     * `admin` dilampirkan di sini, bukan ditambahkan ke config('modules.roles'),
     * karena RoleSeeder menanganinya secara khusus — ia mendapat SELURUH
     * permission, bukan daftar terbatas. Memasukkannya ke registry akan
     * menimpa grant penuh itu saat seeder berjalan.
     *
     * @return array<int, string>
     */
    public static function assignableRoles(): array
    {
        return array_values(array_unique(array_merge(
            ['admin'],
            array_keys((array) config('modules.roles', [])),
        )));
    }

    /**
     * Scope pencarian berdasarkan nama, email, atau telepon.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
