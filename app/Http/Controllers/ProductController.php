<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with(['category', 'variants'])
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->input('search')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'stock_alert' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'variant_name' => ['nullable', 'string', 'max:64'],
            'variant_price' => ['nullable', 'numeric', 'min:0'],
            'variant_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::create([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'base_price' => (int) $data['base_price'],
            'base_cost' => (int) $data['base_cost'],
            'stock_alert' => (int) $data['stock_alert'],
            'is_active' => $request->boolean('is_active', true),
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

        return back()->with('success', 'Produk berhasil ditambahkan.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku,'.$product->id],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'stock_alert' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $product->update([
            'sku' => $data['sku'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'base_price' => (int) $data['base_price'],
            'base_cost' => (int) $data['base_cost'],
            'stock_alert' => (int) $data['stock_alert'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $product->delete(); // soft delete (ADR-0026)

        return back()->with('success', 'Produk berhasil dihapus.');
    }

    /**
     * Update stok variant dari halaman produk.
     */
    public function updateVariantStock(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $variant->product);

        $data = $request->validate([
            'stock' => ['required', 'numeric', 'min:0'],
        ]);

        $variant->update(['stock' => (int) $data['stock']]);

        return back()->with('success', 'Stok variant diperbarui.');
    }
}
