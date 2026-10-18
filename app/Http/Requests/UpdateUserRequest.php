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
     * Form edit selalu mengirim kolom password (meski kosong).
     *
     * `''` tidak dianggap `nullable` oleh validator, jadi tanpa normalisasi ini
     * kolom kosong akan gagal aturan `min:8` — edit profil menjadi mustahil
     * selama password tidak ikut diganti. Kedua kolom dinolkan bersama karena
     * aturan `confirmed` membandingkan password dengan password_confirmation:
     * menolak satu saja mengubah `''` jadi null sementara pasangannya tetap
     * `''`, dan keduanya akan dianggap tidak sama.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('password') === '') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }
    }
}
