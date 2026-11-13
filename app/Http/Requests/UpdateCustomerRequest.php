<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'points' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama pelanggan wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'points.integer' => 'Poin harus berupa bilangan bulat.',
            'points.min' => 'Poin tidak boleh kurang dari 0.',
            'points.max' => 'Jumlah poin terlalu besar.',
        ];
    }
}
