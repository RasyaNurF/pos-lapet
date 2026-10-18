<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Registry Modul & Permission (AGENTS.md §6)
|--------------------------------------------------------------------------
|
| Format permission: <modul>.<aksi>
| Aksi baku: view, create, update, delete, print, export.
| Aksi khusus POS: void (pembatalan transaksi).
|
*/

return [

    'modules' => [
        'products' => [
            'label' => 'Produk',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'categories' => [
            'label' => 'Kategori',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'customers' => [
            'label' => 'Pelanggan',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
        'pos' => [
            'label' => 'Kasir (POS)',
            'actions' => ['view', 'create', 'void'],
        ],
        'sales' => [
            'label' => 'Penjualan',
            'actions' => ['view', 'create'],
        ],
        'shifts' => [
            'label' => 'Shift',
            'actions' => ['view', 'create', 'update'],
        ],
        'transactions' => [
            'label' => 'Transaksi',
            'actions' => ['view', 'print'],
        ],
        'reports' => [
            'label' => 'Laporan',
            'actions' => ['view', 'export'],
        ],
        'audit' => [
            'label' => 'Audit Log',
            'actions' => ['view'],
        ],
        'users' => [
            'label' => 'Pengguna',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
    ],

    /*
    | Role beserta permission yang dimilikinya.
    | admin → semua permission (di-grant penuh di RoleSeeder).
    */
    'roles' => [
        'kasir' => [
            'label' => 'Kasir',
            'permissions' => [
                'sales.view',
                'sales.create',
                'pos.view',
                'pos.create',
                'customers.view',
                'customers.create',
                'products.view',
                'shifts.view',
                'shifts.create',
                'shifts.update',
                'transactions.view',
                'transactions.print',
            ],
        ],
    ],

];
