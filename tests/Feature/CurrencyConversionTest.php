<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\SettingService;
use App\Services\TransactionService;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRate(array $overrides = []): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(array_replace([
            'base' => 'IDR', 'quote' => 'USD', 'rate' => 0.0000625, 'date' => '2026-11-13',
        ], $overrides))]);
    }

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole('admin');

        return $user;
    }

    public function test_dropdown_and_exchange_rate_preview_are_available_to_admin(): void
    {
        $this->fakeRate();
        $this->actingAs($this->admin())->get(route('settings.index'))
            ->assertOk()->assertSee('USD — Dolar Amerika ($)')->assertSee('changeCurrency()', false);
        $this->getJson(route('settings.exchange-rate', ['currency' => 'USD']))
            ->assertOk()->assertJsonPath('rate', 0.0000625)->assertJsonPath('date', '2026-11-13');
    }

    public function test_cashier_cannot_access_rate_settings(): void
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('kasir');
        $this->actingAs($user)->getJson(route('settings.exchange-rate', ['currency' => 'USD']))->assertForbidden();
    }

    public function test_guest_cannot_access_rate_settings(): void
    {
        $this->getJson(route('settings.exchange-rate', ['currency' => 'USD']))->assertUnauthorized();
    }

    public function test_unsupported_currency_is_rejected_without_external_request(): void
    {
        Http::fake();
        $this->actingAs($this->admin())->getJson(route('settings.exchange-rate', ['currency' => 'XYZ']))
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        Http::assertNothingSent();
    }

    public function test_currency_changes_convert_amounts_and_preserve_idr_data(): void
    {
        $this->fakeRate();
        $admin = $this->admin();
        $variant = ProductVariant::factory()->create(['price' => 16000, 'stock' => 5]);
        $settings = app(SettingService::class);
        $settings->set(['currency_symbol' => '$']);
        $this->assertSame('$ 1,00', Money::format(16000));
        $this->assertSame('$ -0,50', Money::format(-8000));
        $this->assertSame('$ 0,00', Money::format(0));
        $this->assertSame(16000, $variant->fresh()->price);
        $transaction = app(TransactionService::class)->processTransaction(
            $admin, [['id' => $variant->id, 'qty' => 1]], null, 'cash', 20000,
        );
        $this->assertSame(17760, $transaction->total);
        $this->actingAs($admin)->get(route('transactions.receipt', $transaction))
            ->assertOk()->assertSee('$ 1,11');
        $settings->set(['currency_symbol' => 'Rp']);
        $this->assertSame('Rp 16.000', Money::format(16000));
        $this->assertSame(17760, $transaction->fresh()->total);
    }

    public function test_saving_currency_uses_server_rate_and_ignores_client_rate(): void
    {
        $this->fakeRate();
        $payload = collect(config('settings.items'))->map(fn (array $item) => $item['default'])->all();
        $payload['currency_symbol'] = '$';
        $payload['currency_decimals'] = 2;
        $payload['exchange_rate'] = 999;
        $this->actingAs($this->admin())->put(route('settings.update'), $payload)
            ->assertRedirect(route('settings.index'))->assertSessionHasNoErrors();
        $this->assertSame('$ 1,00', Money::format(16000));
        $this->get(route('dashboard'))->assertOk()->assertSee('Tampilan USD');
    }

    public function test_rates_are_cached_and_refreshed_after_an_hour(): void
    {
        $this->fakeRate();
        (new ExchangeRateService)->quote('USD');
        (new ExchangeRateService)->quote('USD');
        Http::assertSentCount(1);
        $this->travel(61)->minutes();
        (new ExchangeRateService)->quote('USD');
        Http::assertSentCount(2);
    }

    public function test_outage_uses_last_good_rate_with_stale_status(): void
    {
        $this->fakeRate();
        (new ExchangeRateService)->quote('USD');
        Cache::forget('exchange-rate.IDR.USD');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $quote = (new ExchangeRateService)->quote('USD');
        $this->assertTrue($quote['stale']);
        $this->assertSame(0.0000625, $quote['rate']);
    }

    public function test_outage_without_cached_rate_blocks_currency_change(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        try {
            app(SettingService::class)->set(['currency_symbol' => '$']);
            $this->fail('Currency change should fail without an exchange rate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_symbol', $e->errors());
        }
        $this->assertSame('Rp', app(SettingService::class)->get('currency_symbol'));
    }

    public function test_invalid_rate_response_is_rejected(): void
    {
        $this->fakeRate(['rate' => -1]);
        $this->actingAs($this->admin())->getJson(route('settings.exchange-rate', ['currency' => 'USD']))
            ->assertStatus(503);
        $this->assertNull(Cache::get('exchange-rate.IDR.USD'));
    }

    public function test_missing_rate_never_labels_unconverted_rupiah_as_dollars(): void
    {
        $this->fakeRate();
        app(SettingService::class)->set(['currency_symbol' => '$']);
        Cache::flush();
        $this->app->forgetScopedInstances();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame('Rp 16.000', Money::format(16000));
        $this->assertSame('Rp 32.000', Money::format(32000));
    }
}
