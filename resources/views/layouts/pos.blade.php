<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'POS' }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-screen flex flex-col bg-base-200">

    {{-- Bar aksi POS: cegah halaman menjadi jalan buntu (Tahap 7) --}}
    <header class="flex items-center gap-3 px-4 py-2 bg-base-100 border-b border-base-300 shrink-0">
        <span class="font-bold text-lg whitespace-nowrap">{{ config('app.name') }}</span>

        @auth
            @if(auth()->user()->hasOpenShift())
                <span class="badge badge-success">Shift Buka</span>
            @else
                <span class="badge badge-ghost">Shift Tutup</span>
            @endif

            <div class="flex-1"></div>

            <a href="{{ route('dashboard') }}"
               class="btn btn-ghost btn-sm min-h-[44px] px-4">
                ← Dashboard
            </a>

            @if(auth()->user()->hasOpenShift())
                <button type="button"
                        onclick="confirmCloseShift()"
                        class="btn btn-error btn-sm text-white min-h-[44px] px-4">
                    🔒 Tutup Shift
                </button>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm min-h-[44px] px-4">Logout</button>
            </form>
        @endauth
    </header>

    @include('currencies.status')
    <div class="flex-1 min-h-0 overflow-hidden">
        {{ $slot }}
    </div>

    @livewireScripts

    {{-- SweetAlert2 (AGENTS.md §5) --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- Modal struk cetak (DESIGN.md §5) --}}
    <div id="pos-receipt-modal" class="fixed inset-0 z-50 items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-base-300/70" onclick="hidePosReceipt()"></div>
        <div class="relative bg-base-100 rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto p-3">
            <div id="pos-receipt-content"></div>
            <div class="flex gap-2 mt-2">
                <button onclick="window.print()" class="btn btn-primary flex-1 min-h-[48px]">🖨 Cetak Struk</button>
                <button onclick="hidePosReceipt()" class="btn btn-ghost flex-1 min-h-[48px]">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        function hidePosReceipt() {
            document.getElementById('pos-receipt-modal').style.display = 'none';
        }

        // Konfirmasi tutup shift (AGENTS.md §5 — aksi destruktif wajib swal).
        function confirmCloseShift() {
            Swal.fire({
                icon: 'warning',
                title: 'Tutup shift sekarang?',
                text: 'Pastikan semua transaksi selesai. Anda akan diminta mengisi kas akhir.',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, tutup shift',
                cancelButtonText: 'Batal',
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = @json(route('shifts.close.form'));
                }
            });
        }

        document.addEventListener('livewire:init', () => {
            const swalDefault = {
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal',
            };

            Livewire.on('swal:success', ({ title, text }) => {
                Swal.fire({ icon: 'success', title, text, ...swalDefault, confirmButtonText: 'OK' });
            });

            Livewire.on('swal:error', ({ title, text }) => {
                Swal.fire({ icon: 'error', title, text, ...swalDefault, confirmButtonText: 'OK' });
            });

            Livewire.on('swal:confirm', ({ title, text, callback, id }) => {
                Swal.fire({
                    icon: 'warning',
                    title: title ?? 'Konfirmasi',
                    text: text ?? 'Apakah Anda yakin?',
                    showCancelButton: true,
                    ...swalDefault,
                }).then((result) => {
                    if (result.isConfirmed) {
                        Livewire.dispatch(callback ?? 'confirmed', { id });
                    }
                });
            });

            // Setelah checkout sukses → tampilkan struk untuk dicetak.
            Livewire.on('print-receipt', async ({ transaction_id }) => {
                try {
                    const response = await fetch(`/transactions/${transaction_id}/receipt`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!response.ok) throw new Error('Gagal memuat struk');
                    document.getElementById('pos-receipt-content').innerHTML = await response.text();
                    document.getElementById('pos-receipt-modal').style.display = 'flex';
                } catch (e) {
                    // Struk gagal dimuat — transaksi tetap tersimpan.
                }
            });
        });
    </script>
</body>
</html>
