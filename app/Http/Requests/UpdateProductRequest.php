<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku,'.$productId],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'stock_alert' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
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
        ];
    }
}
