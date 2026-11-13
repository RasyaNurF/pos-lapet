<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Logika bisnis master produk (AGENTS.md §3 — controller tipis, logic di Service).
 */
class ProductService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Buat produk baru beserta varian default (jika nama varian diisi).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $product = Product::create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => $data['category_id'] ?? null,
                'base_price' => (int) $data['base_price'],
                'base_cost' => (int) $data['base_cost'],
                'stock_alert' => (int) $data['stock_alert'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            // Varian default bila nama varian diisi.
            if (! empty($data['variant_name'])) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name' => $data['variant_name'],
                    'sku' => $product->sku.'-V1',
                    'price' => (int) ($data['variant_price'] ?? $data['base_price']),
                    'cost' => (int) $data['base_cost'],
                    'stock' => (int) ($data['variant_stock'] ?? 0),
                ]);
            }

            $this->audit->log('product.create', $product, null, $product->only(
                ['sku', 'name', 'category_id', 'base_price', 'base_cost', 'stock_alert', 'is_active'],
            ));

            return $product;
        });
    }

    /**
     * Perbarui data produk.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $original = $product->getOriginal();

            $product->update([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => $data['category_id'] ?? null,
                'base_price' => (int) $data['base_price'],
                'base_cost' => (int) $data['base_cost'],
                'stock_alert' => (int) $data['stock_alert'],
                'is_active' => (bool) ($data['is_active'] ?? $product->is_active),
            ]);

            // Harga POS tersimpan per varian agar ukuran seperti kg dan pcs
            // dapat memiliki harga yang berbeda.
            foreach ($data['variants'] ?? [] as $variantId => $variantData) {
                $product->variants()
                    ->whereKey($variantId)
                    ->update([
                        'price' => (int) $variantData['price'],
                        'cost' => (int) $variantData['cost'],
                    ]);
            }

            $changes = $this->audit->changes($product, $original);

            if ($changes['old'] !== []) {
                $this->audit->log('product.update', $product, $changes['old'], $changes['new']);
            }

            return $product;
        });
    }

    /**
     * Hapus produk (soft delete — ADR-0026) beserta jejak auditnya.
     */
    public function delete(Product $product): Product
    {
        $snapshot = $product->only(['sku', 'name', 'base_price', 'base_cost']);

        $product->delete();

        $this->audit->log('product.delete', $product, $snapshot, null);

        return $product;
    }

    /**
     * Simpan nilai stok varian (mutasi stok dari halaman admin).
     *
     * Celah fraud internal bila tidak dicatat: kasir bisa menaikkan stok sendiri
     * tanpa jejak. Sekarang selalu tercatat old → new + pelaku.
     */
    public function updateVariantStock(ProductVariant $variant, int $stock): ProductVariant
    {
        $oldStock = (int) $variant->stock;

        $variant->update(['stock' => $stock]);

        $this->audit->log('stock.adjust', $variant, ['stock' => $oldStock], ['stock' => $stock]);

        return $variant;
    }
}
