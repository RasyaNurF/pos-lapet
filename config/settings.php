<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Registry Pengaturan Toko (Store Config)
|--------------------------------------------------------------------------
|
| Meniru pola `config/modules.php`: satu sumber kebenaran untuk default,
| label, grup tampilan, dan aturan validasi tiap pengaturan.
|
| Tipe nilai ditentukan SECARA IMPLISIT oleh tipe `default`:
|   bool  -> disimpan '1' / '' lalu dibaca kembali boolean
|   int   -> disimpan string lalu dibaca kembali integer
|   lainnya -> string
|
| PERINGATAN: nilai `tax.rate` di sini HANYA berlaku untuk transaksi BARU.
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
        | Tampil di struk cetak. `company.name` menggantikan hardcode
        | "LA PET GALLERY" lama di transactions/receipt.blade.php.
        */
        'company.name' => [
            'group' => 'information',
            'label' => 'Nama Toko',
            'default' => 'LA PET GALLERY',
            'rules' => ['required', 'string', 'max:120'],
        ],
        'company.address' => [
            'group' => 'information',
            'label' => 'Alamat Toko',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:500'],
        ],
        'company.phone' => [
            'group' => 'information',
            'label' => 'Telepon Toko',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:30'],
        ],
        'company.email' => [
            'group' => 'information',
            'label' => 'Email',
            'default' => '',
            'rules' => ['nullable', 'email', 'max:120'],
        ],
        'company.website' => [
            'group' => 'information',
            'label' => 'Website',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:120'],
        ],
        'company.logo' => [
            'group' => 'information',
            'label' => 'Logo Toko',
            'default' => '',
            'rules' => ['nullable', 'string', 'max:255'],
        ],

        /*
        |------------------------------------------------------------------
        | Umum
        |------------------------------------------------------------------
        | `tax.rate` menggantikan hardcode `0.11` di TransactionService
        | dan PosTerminal. `tax.included` mengubah CARA PPN dihitung:
        | aktif -> PPN dikeluarkan dari subtotal, bukan ditambahkan.
        */
        'tax.rate' => [
            'group' => 'general',
            'label' => 'Tarif PPN (%)',
            'default' => 11,
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],
        'tax.included' => [
            'group' => 'general',
            'label' => 'PPN sudah termasuk harga',
            'default' => false,
            'rules' => ['boolean'],
        ],
        'sales.default_discount' => [
            'group' => 'general',
            'label' => 'Diskon Penjualan Default (%)',
            'default' => 0,
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],

        /*
        |------------------------------------------------------------------
        | Lokalisasi
        |------------------------------------------------------------------
        | Default di bawah mereproduasi perilaku lama persis:
        |   number_format($x, 0, ',', '.') => "1.234.567" lalu "Rp " di depan.
        */
        'currency.symbol' => [
            'group' => 'localisation',
            'label' => 'Simbol Mata Uang',
            'default' => 'Rp',
            'rules' => ['required', 'string', 'max:10'],
        ],
        'currency.position' => [
            'group' => 'localisation',
            'label' => 'Posisi Simbol',
            'default' => 'left',
            'rules' => ['required', 'in:left,right'],
        ],
        'currency.decimals' => [
            'group' => 'localisation',
            'label' => 'Jumlah Desimal',
            'default' => 0,
            'rules' => ['required', 'integer', 'min:0', 'max:4'],
        ],
        'currency.thousands_separator' => [
            'group' => 'localisation',
            'label' => 'Pemisah Ribuan',
            'default' => '.',
            'rules' => ['nullable', 'string', 'max:1'],
        ],
        'currency.decimal_separator' => [
            'group' => 'localisation',
            'label' => 'Pemisah Desimal',
            'default' => ',',
            'rules' => ['nullable', 'string', 'max:1'],
        ],

    ],

];
