<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Registry Pengaturan Toko (Store Config)
|--------------------------------------------------------------------------
|
| Meniru pola `config/modules.php`: satu sumber kebenaran untuk default,
| label, grup tampilan, tipe input, dan aturan validasi tiap pengaturan.
|
| Halaman /settings dirender PENUH dari registry ini — menambah pengaturan
| baru cukup menambah entri di sini, tanpa menyentuh view.
|
| NAMA KEY WAJIB GARIS BAWAH, BUKAN TITIK.
|
| Validator Laravel menafsirkan titik sebagai akses array bersarang, sehingga
| rule 'company.name' mencari $data['company']['name'] dan tidak akan pernah
| menemukan input datar 'company.name'. Akibatnya SEMUA rule "required" gagal
| diam-diam walau datanya sudah benar. Ini sudah dibuktikan langsung:
|
|   Validator::make(['company.name' => 'x'], ['company.name' => 'required'])
|       ->fails()   // true
|       ->getValue('company.name')   // null
|
| Karena itu titik dipakai untuk permission (`products.view` di config/modules.php)
| yang tidak pernah melewati validator, tetapi TIDAK untuk key pengaturan ini.
|
| Tipe nilai ditentukan SECARA IMPLISIT oleh tipe `default`:
|   bool  -> disimpan '1' / '' lalu dibaca kembali boolean
|   float -> disimpan string lalu dibaca kembali float
|   lainnya -> string
|
| `type` di bawah adalah TIPE INPUT di form (text, number, checkbox, ...),
| bukan tipe nilai — jangan tertukar.
|
| PERINGATAN: nilai `tax_rate` di sini HANYA berlaku untuk transaksi BARU.
| Tarif yang benar-benar dipakai tiap transaksi tersimpan di kolom
| `transactions.tax_rate`, sehingga mengubah PPN tidak mengubah riwayat
| struk lama.
|
| Syarat mutlak: default di bawah harus mereproduksi format lama secara
| IDENTIK — `Rp 1.234.567` — karena seluruh laporan & struk memakainya.
|
*/

return [

    'groups' => [
        'information' => 'Informasi Toko',
        'general' => 'Umum',
        'localisation' => 'Lokalisasi',
    ],

    'items' => [

        /*
        |------------------------------------------------------------------
        | Informasi Toko
        |------------------------------------------------------------------
        | Tampil di struk cetak. `company_name` menggantikan hardcode
        | "LA PET GALLERY" lama di transactions/receipt.blade.php.
        |
        | `company_logo` sengaja memakai type 'file': nilainya path di disk
        | publik, dan diunggah lewat mekanisme khusus di SettingController —
        | bukan lewat loop generik.
        */
        'company_name' => [
            'group' => 'information',
            'label' => 'Nama Toko',
            'type' => 'text',
            'default' => 'LA PET GALLERY',
            'rules' => ['required', 'string', 'max:120'],
        ],
        'company_address' => [
            'group' => 'information',
            'label' => 'Alamat Toko',
            'type' => 'textarea',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:500'],
        ],
        'company_phone' => [
            'group' => 'information',
            'label' => 'Telepon Toko',
            'type' => 'tel',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:30'],
        ],
        'company_email' => [
            'group' => 'information',
            'label' => 'Email',
            'type' => 'email',
            'default' => '',
            'rules' => ['nullable', 'email', 'max:120'],
        ],
        'company_website' => [
            'group' => 'information',
            'label' => 'Website',
            'type' => 'url',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:120'],
        ],
        'company_logo' => [
            'group' => 'information',
            'label' => 'Logo Toko',
            'type' => 'file',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        /*
        |------------------------------------------------------------------
        | Umum
        |------------------------------------------------------------------
        | `tax_rate` menggantikan hardcode `0.11` di TransactionService
        | dan PosTerminal. `tax_included` mengubah CARA PPN dihitung:
        | aktif -> PPN dikeluarkan dari subtotal, bukan ditambahkan.
        |
        | default ditulis 11.0 (bukan 11): tipe default menentukan tipe nilai,
        | dan integer akan memotong tarif pecahan 11.5% menjadi 11%.
        */
        'tax_rate' => [
            'group' => 'general',
            'label' => 'Tarif PPN (%)',
            'type' => 'number',
            'default' => 11.0,
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],
        'tax_included' => [
            'group' => 'general',
            'label' => 'PPN sudah termasuk harga',
            'type' => 'checkbox',
            'default' => false,
            'rules' => ['boolean'],
        ],
        'sales_default_discount' => [
            'group' => 'general',
            'label' => 'Diskon Penjualan Default (%)',
            'type' => 'number',
            'default' => 0.0,
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],

        /*
        |------------------------------------------------------------------
        | Lokalisasi
        |------------------------------------------------------------------
        | Default di bawah mereproduksi perilaku lama persis:
        |   number_format($x, 0, ',', '.') => "1.234.567" lalu "Rp " di depan.
        */
        'currency_symbol' => [
            'group' => 'localisation',
            'label' => 'Simbol Mata Uang',
            'type' => 'currency',
            'default' => 'Rp',
            'rules' => ['required', 'string', 'in:'.implode(',', array_keys(require __DIR__.'/currencies.php'))],
        ],
        'currency_position' => [
            'group' => 'localisation',
            'label' => 'Posisi Simbol',
            'type' => 'select',
            'default' => 'left',
            'rules' => ['required', 'in:left,right'],
        ],
        'currency_decimals' => [
            'group' => 'localisation',
            'label' => 'Jumlah Desimal',
            'type' => 'number',
            'default' => 0,
            'rules' => ['required', 'integer', 'min:0', 'max:4'],
        ],
        'currency_thousands_separator' => [
            'group' => 'localisation',
            'label' => 'Pemisah Ribuan',
            'type' => 'text',
            'default' => '.',
            'rules' => ['nullable', 'string', 'max:1'],
        ],
        'currency_decimal_separator' => [
            'group' => 'localisation',
            'label' => 'Pemisah Desimal',
            'type' => 'text',
            'default' => ',',
            'rules' => ['nullable', 'string', 'max:1'],
        ],

    ],

];
