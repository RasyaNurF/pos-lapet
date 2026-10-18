<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            /*
             | Ditetapkan eksplisit, bukan diserahkan ke default database.
             |
             | Eloquent hanya mengembalikan atribut yang ia kirim saat INSERT;
             | nilai yang diisi database tidak pernah masuk ke model hasil
             | factory. Akibatnya $user->is_active terbaca null dan cast
             | boolean tidak pernah sempat bekerja, sampai model dibaca ulang.
             |
             | Alasan sama untuk shift_status: ia tidak punya cast, jadi
             | ketiadaannya tidak menimbulkan error — hanya perbandingan
             | `=== 'open'` yang diam-diam membandingkan null. Nilainya memang
             | kebetulan sama dengan default database, tapi mengandalkan
             | kebetulan itu berarti guard yang membaca kolom ini bisa gagal
             | terbuka lebar bila default suatu saat berubah.
             */
            'is_active' => true,
            'shift_status' => 'closed',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
