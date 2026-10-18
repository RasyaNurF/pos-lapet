<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\TransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SweetAlertConsistencyTest extends TestCase
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

    /**
     * Halaman yang menampilkan tombol hapus atau void.
     *
     * @return list<string>
     */
    private function destructiveRoutes(): array
    {
        return [
            'products.index',
            'categories.index',
            'customers.index',
            'transactions.index',
        ];
    }

    /**
     * Tombol konfirmasi hanya ikut ter-render kalau barisnya ada,
     * jadi tiap halaman perlu setidaknya satu data.
     */
    private function giveEveryPageSomethingToDelete(): void
    {
        Category::factory()->create();
        Customer::factory()->create();
        Product::factory()->create();

        $variant = ProductVariant::factory()->create(['stock' => 10, 'price' => 10000]);

        app(TransactionService::class)->processTransaction(
            user: $this->admin,
            cart: [['id' => $variant->id, 'name' => $variant->display_name, 'price' => 10000, 'qty' => 1]],
            customerId: null,
            paymentMethod: 'cash',
            amountTendered: 100000,
        );
    }

    public function test_native_browser_confirm_is_gone_from_every_destructive_page(): void
    {
        foreach ($this->destructiveRoutes() as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertDontSee('onsubmit="return confirm(', false);
        }
    }

    public function test_every_destructive_page_routes_its_confirmation_through_swalconfirm(): void
    {
        $this->giveEveryPageSomethingToDelete();

        foreach ($this->destructiveRoutes() as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee('onsubmit="swalConfirm(', false);
        }
    }

    public function test_void_form_asks_for_post_instead_of_delete(): void
    {
        $this->giveEveryPageSomethingToDelete();

        $this->actingAs($this->admin)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertSee("method: 'POST'", false)
            ->assertDontSee("method: 'DELETE'", false);
    }

    public function test_delete_forms_keep_their_csrf_and_method_spoofing(): void
    {
        $this->giveEveryPageSomethingToDelete();

        foreach (['products.index', 'categories.index', 'customers.index'] as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee('name="_token"', false)
                ->assertSee('value="DELETE"', false);
        }
    }

    /**
     * Kutip tunggal adalah pemicu sebenarnya: `{{ }}` meng-escape-nya jadi
     * `&#039;` di HTML, tapi browser mengembalikannya lagi saat membaca
     * atribut — sehingga string JS yang dibungkus kutip tunggal terputus.
     */
    public function test_a_name_with_quotes_renders_safely_into_data_title(): void
    {
        Category::factory()->create(['name' => "Kado 'Milo'"]);

        $this->actingAs($this->admin)
            ->get(route('categories.index'))
            ->assertOk()
            ->assertSee('data-title="Hapus kategori Kado &#039;Milo&#039;?"', false);
    }
}
