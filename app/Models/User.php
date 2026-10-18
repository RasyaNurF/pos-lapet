<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
}
