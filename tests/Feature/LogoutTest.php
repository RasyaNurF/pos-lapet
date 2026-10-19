<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole('admin');
    }

    public function test_logout_posts_and_ends_the_session(): void
    {
        $this->actingAs($this->admin);

        $this->assertAuthenticated();

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_logout_invalidate_the_session_token_so_a_back_button_cannot_reuse_it(): void
    {
        $this->actingAs($this->admin);

        $token = session()->token();

        $this->post(route('logout'));

        $this->assertNotSame($token, session()->token());
    }

    public function test_header_dropdown_renders_logout_as_a_post_form_with_csrf(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('_token', false);
    }

    public function test_logout_button_is_a_real_submit_button_with_a_44px_tap_target(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*action="'.preg_quote(route('logout'), '/').'".*?<button[^>]*type="submit"[^>]*>/s',
            $html,
            'Tombol logout harus berupa <button type="submit"> di dalam form POST logout.'
        );

        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*min-h-\[44px\]/',
            $html,
            'Tombol logout wajib punya tap target minimal 44px (AGENTS.md bagian 4).'
        );
    }
}
