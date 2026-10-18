<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi sesungguhnya lewat middleware permission:settings.update.
        return true;
    }

    /**
     * Rules dibangun dari registry, sehingga aturan validasi di HTTP TIDAK
     * BISA terpisah dari aturan yang dipakai SettingService. Menulis ulang
     * rules di sini hanya membuat keduanya lama-lama berbeda.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (config('settings.items', []) as $key => $config) {
            $rules[$key] = $config['rules'];
        }

        $rules['logo'] = ['nullable', 'image', 'max:2048'];
        $rules['remove_logo'] = ['nullable', 'boolean'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.image' => 'Logo harus berupa file gambar.',
            'logo.max' => 'Ukuran logo maksimal 2 MB.',
        ];
    }
}
