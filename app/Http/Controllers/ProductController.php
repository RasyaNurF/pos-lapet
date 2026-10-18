<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Requests\UpdateVariantStockRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

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

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $this->productService->create($request->validated());

        return back()->with('success', 'Produk berhasil ditambahkan.');
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $this->productService->update($product, $request->validated());

        return back()->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->productService->delete($product);

        return back()->with('success', 'Produk berhasil dihapus.');
    }

    /**
     * Update stok variant dari halaman produk.
     */
    public function updateVariantStock(UpdateVariantStockRequest $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $variant->product);

        $this->productService->updateVariantStock($variant, (int) $request->validated('stock'));

        return back()->with('success', 'Stok variant diperbarui.');
    }
}
