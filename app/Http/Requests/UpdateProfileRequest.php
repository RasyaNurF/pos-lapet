<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ubah data diri & password sendiri.
 *
 * `role`, `is_active`, dan `hire_date` sengaja TIDAK ada di rules(). `validated()`
 * hanya mengembalikan kunci yang divalidasi, jadi kirim apa pun lewat endpoint
 * ini — `role=admin` misalnya — nilainya dibuang mentah-mentah sebelum sampai
 * ke service. Eskalasi lewat profil sendiri tidak mungkin terjadi.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Ditentukan di sini (bukan required_if) supaya tidak ada aturan yang
        // saling menimpa antara `nullable` dan `required_if` pada kolom yang
        // sama — dua aturan itu punya perilaku saat value null yang mudah
        // salah kaprah.
        $changingPassword = filled($this->input('password'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->getAuthIdentifier()),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],

            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => $changingPassword
                ? ['required', 'current_password:web']
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai karyawan lain.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak sama.',
            'current_password.required' => 'Masukkan password Anda saat ini untuk mengganti password.',
            'current_password.current_password' => 'Password saat ini salah.',
        ];
    }

    /**
     * Menormalkan kolom password yang kosong (lihat catatan di UpdateUserRequest).
     */
    protected function prepareForValidation(): void
    {
        $empty = [];

        foreach (['password', 'password_confirmation', 'current_password'] as $key) {
            if ($this->input($key) === '' || $this->input($key) === null) {
                $empty[$key] = null;
            }
        }

        if ($empty !== []) {
            $this->merge($empty);
        }
    }
}
