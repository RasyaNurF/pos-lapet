<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi diserahkan ke Policy + middleware `permission:users.update`
        // di route — sama seperti FormRequest lain (AGENTS.md §3).
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            // Boleh kosong — berarti password lama dipertahankan.
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(User::assignableRoles())],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama karyawan wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai karyawan lain.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'role.required' => 'Role wajib dipilih.',
            'role.in' => 'Role yang dipilih tidak dikenal.',
            'is_active.required' => 'Status aktif wajib ditentukan.',
        ];
    }

    /**
     * Kolom password form edit selalu ikut terkirim, meski kosong.
     *
     * Global middleware `ConvertEmptyStringsToNull` biasanya sudah mengubah
     * `''` jadi null sebelum FormRequest ini jalan, sehingga aturan `nullable`
     * berlaku dan `confirmed` membandingkan null dengan null. Normalisasi
     * eksplisit dipertahankan supaya aturan ini tidak diam-diam bergantung
     * pada konfigurasi middleware global: bila middleware itu suatu saat
     * dilepas, kolom kosong akan mulai gagal `min:8` — dan gejalanya cuma
     * "edit profil tidak bisa disimpan", tanpa sebab yang jelas.
     *
     * Kedua kolom dinolkan bersama karena `confirmed` membandingkan password
     * dengan password_confirmation; menolak satu saja membuat keduanya
     * dianggap tidak sama.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('password') === '' || $this->input('password') === null) {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }
    }
}
