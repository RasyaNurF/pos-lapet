<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Layer 2 Access Guard — anti brute force login.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $user->assignRole('kasir');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_sixth_failed_attempt_is_rate_limited(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $user->assignRole('kasir');

        // 5 percobaan gagal pertama → 302 (redirect kembali dengan error validasi).
        foreach (range(1, 5) as $i) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'salah-'.$i,
            ])->assertStatus(302);
        }

        // Percobaan ke-6 dengan request JSON → 429 Too Many Attempts.
        $this->postJson(route('login.store'), [
            'email' => $user->email,
            'password' => 'salah-6',
        ])->assertStatus(429);
    }

    public function test_blocked_user_cannot_login_even_with_correct_password(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $user->assignRole('kasir');

        // Kuras 5 percobaan gagal.
        foreach (range(1, 5) as $i) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'salah-'.$i,
            ]);
        }

        // Percobaan ke-6 memakai password yang BENAR pun tetap ditolak
        // dan menampilkan pesan throttle (bukan "password salah").
        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Terlalu banyak percobaan',
            session('errors')->first('email'),
        );

        // Pastikan benar-benar tidak ter-authenticate.
        $this->assertGuest();
    }

    public function test_rate_limit_is_per_email_not_global(): void
    {
        $victim = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $victim->assignRole('kasir');

        $other = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $other->assignRole('kasir');

        // Kuras rate limit untuk email victim.
        foreach (range(1, 5) as $i) {
            $this->post(route('login.store'), [
                'email' => $victim->email,
                'password' => 'salah-'.$i,
            ]);
        }

        // Email lain masih bisa login (limit per email+IP, bukan global).
        $this->post(route('login.store'), [
            'email' => $other->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_successful_login_clears_the_counter(): void
    {
        $user = User::factory()->create(['role' => 'kasir', 'password' => 'password']);
        $user->assignRole('kasir');

        // 3 gagal dulu.
        foreach (range(1, 3) as $i) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'salah-'.$i,
            ]);
        }

        // Login sukses → counter bersih.
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse(RateLimiter::tooManyAttempts(
            'login:'.sha1(strtolower($user->email).'|127.0.0.1'),
            5,
        ));
    }
}
