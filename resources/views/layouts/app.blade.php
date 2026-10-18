<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Admin' }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-base-200">

    {{-- Navbar --}}
    <div class="navbar bg-base-100 shadow-sm sticky top-0 z-30">
        <div class="flex-1 px-2">
            <a href="{{ route('dashboard') }}" class="btn btn-ghost text-xl font-bold">{{ config('app.name') }}</a>
        </div>
        <div class="flex-none gap-2">
            @auth
                <a href="{{ route('pos') }}" class="btn btn-success btn-sm min-h-[44px]">🧾 Kasir (POS)</a>
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost">{{ auth()->user()->name }} ({{ auth()->user()->role }})</div>
                    <ul tabindex="0" class="menu menu-sm dropdown-content bg-base-100 rounded-box z-40 mt-2 w-52 p-2 shadow">
                        <li><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Logout</button></form></li>
                    </ul>
                </div>
            @endauth
        </div>
    </div>

    {{-- Sidebar + konten --}}
    <div class="flex">
        <aside class="w-56 min-h-[calc(100vh-64px)] bg-base-100 border-r border-base-300 p-3">
            <ul class="menu gap-1">
                <li><a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">📊 Dashboard</a></li>
                @can('products.view')
                    <li><a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">📦 Produk</a></li>
                @endcan
                @can('categories.view')
                    <li><a href="{{ route('categories.index') }}" class="{{ request()->routeIs('categories.*') ? 'active' : '' }}">🏷️ Kategori</a></li>
                @endcan
                @can('customers.view')
                    <li><a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'active' : '' }}">👥 Pelanggan</a></li>
                @endcan
                @can('pos.view')
                    <li><a href="{{ route('pos') }}" class="{{ request()->routeIs('pos') ? 'active' : '' }}">🧾 Kasir (POS)</a></li>
                @endcan
                @can('shifts.view')
                    <li>
                        <a href="{{ auth()->user()->hasOpenShift() ? route('shifts.close.form') : route('shifts.open.form') }}"
                           class="{{ request()->routeIs('shifts.open*|shifts.close*') ? 'active' : '' }}">
                            ⏱️ Shift
                            @if(auth()->user()->hasOpenShift())
                                <span class="badge badge-success badge-sm ml-auto">Buka</span>
                            @else
                                <span class="badge badge-ghost badge-sm ml-auto">Tutup</span>
                            @endif
                        </a>
                    </li>
                @endcan
                @can('reports.view')
                    <li><a href="{{ route('shifts.index') }}" class="{{ request()->routeIs('shifts.index') ? 'active' : '' }}">🗓️ Riwayat Shift</a></li>
                @endcan
                @can('transactions.view')
                    <li><a href="{{ route('transactions.index') }}" class="{{ request()->routeIs('transactions.*') ? 'active' : '' }}">📄 Transaksi</a></li>
                @endcan
                @can('reports.view')
                    <li><a href="{{ route('reports.sales') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">📈 Laporan</a></li>
                @endcan
                @can('reports.view')
                    <li><a href="{{ route('reports.stock') }}" class="{{ request()->routeIs('reports.stock') ? 'active' : '' }}">📉 Stok Menipis</a></li>
                @endcan
                @can('audit.view')
                    <li><a href="{{ route('audit-logs.index') }}" class="{{ request()->routeIs('audit-logs.*') ? 'active' : '' }}">🧾 Audit Log</a></li>
                @endcan
                @can('settings.view')
                    <li><a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">⚙️ Pengaturan Toko</a></li>
                @endcan
            </ul>
        </aside>

        <main class="flex-1 p-6">
            @if(session('success'))
                <div class="alert alert-success mb-4">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-error mb-4">{{ session('error') }}</div>
            @endif

            @yield('content')
        </main>
    </div>

    @livewireScripts

    {{-- SweetAlert2 (AGENTS.md §5) --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const swalDefault = {
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Ya',
            cancelButtonText: 'Batal',
        };

        function swalSuccess(title, text) {
            Swal.fire({ icon: 'success', title, text, ...swalDefault, confirmButtonText: 'OK' });
        }

        function swalError(title, text) {
            Swal.fire({ icon: 'error', title, text, ...swalDefault, confirmButtonText: 'OK' });
        }

        function swalConfirm({ title, text, url }) {
            Swal.fire({
                icon: 'warning',
                title: title ?? 'Konfirmasi',
                text: text ?? 'Apakah Anda yakin?',
                showCancelButton: true,
                ...swalDefault,
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = url;
                    form.innerHTML = '@csrf<input type="hidden" name="_method" value="DELETE">';
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    </script>
    @stack('scripts')
</body>
</html>
