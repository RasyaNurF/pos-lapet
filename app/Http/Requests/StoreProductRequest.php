<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi tetap di Policy lewat ProductController::store().
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'stock_alert' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'variant_name' => ['nullable', 'string', 'max:64'],
            'variant_price' => ['nullable', 'numeric', 'min:0'],
            'variant_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.required' => 'SKU wajib diisi.',
            'sku.unique' => 'SKU sudah dipakai produk lain.',
            'name.required' => 'Nama produk wajib diisi.',
            'base_price.required' => 'Harga jual wajib diisi.',
            'base_price.min' => 'Harga jual tidak boleh negatif.',
            'base_cost.required' => 'Harga modal wajib diisi.',
            'base_cost.min' => 'Harga modal tidak boleh negatif.',
            'stock_alert.min' => 'Batas stok minimum tidak boleh negatif.',
            'category_id.exists' => 'Kategori terpilih tidak ditemukan.',
            'variant_price.min' => 'Harga varian tidak boleh negatif.',
            'variant_stock.min' => 'Stok varian tidak boleh negatif.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['is_active' => false]);
    }
}
