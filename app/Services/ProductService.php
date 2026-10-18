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
        $product->update([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'base_price' => (int) $data['base_price'],
            'base_cost' => (int) $data['base_cost'],
            'stock_alert' => (int) $data['stock_alert'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return $product;
    }

    /**
     * Simpan nilai stok varian (mutasi stok dari halaman admin).
     */
    public function updateVariantStock(ProductVariant $variant, int $stock): ProductVariant
    {
        $variant->update(['stock' => $stock]);

        return $variant;
    }
}
