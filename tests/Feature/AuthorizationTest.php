<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create(['role' => 'kasir']);
        $this->kasir->assignRole('kasir');
    }

    public function test_kasir_cannot_access_reports(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('reports.sales'))
            ->assertForbidden();

        $this->actingAs($this->kasir)
            ->get(route('reports.stock'))
            ->assertForbidden();
    }

    public function test_kasir_cannot_export_reports(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('reports.export'))
            ->assertForbidden();
    }

    public function test_kasir_can_access_pos_and_customers(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('pos'))
            ->assertRedirect(); // dialihkan ke buka shift (belum ada shift aktif)

        $this->actingAs($this->kasir)
            ->get(route('customers.index'))
            ->assertOk();
    }

    public function test_pos_requires_open_shift(): void
    {
        // Kasir dengan shift terbuka tetap lolos middleware shift.open
        // (tidak redirect ke form shift).
        $this->kasir->update(['shift_status' => 'open']);

        $this->actingAs($this->kasir)
            ->get(route('pos'))
            ->assertOk();
    }

    public function test_kasir_cannot_create_category(): void
    {
        $this->actingAs($this->kasir)
            ->post(route('categories.store'), ['name' => 'Barang'])
            ->assertForbidden();
    }

    public function test_kasir_cannot_delete_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->kasir)
            ->delete(route('products.destroy', $product))
            ->assertForbidden();
    }
}
