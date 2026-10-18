@extends('layouts.app')

@section('content')
    <h1 class="text-2xl font-bold mb-4">Riwayat Transaksi</h1>

    {{-- Filter tanggal --}}
    <form method="GET" class="flex flex-wrap gap-2 items-end mb-4">
        <div>
            <label class="block text-sm mb-1">Dari</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="input input-bordered h-12">
        </div>
        <div>
            <label class="block text-sm mb-1">Sampai</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="input input-bordered h-12">
        </div>
        <button type="submit" class="btn btn-primary min-h-[48px]">Filter</button>
        <a href="{{ route('transactions.index') }}" class="btn btn-ghost min-h-[48px]">Reset</a>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($transactions as $transaction)
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <div class="font-bold font-mono">{{ $transaction->invoice_no }}</div>
                            <div class="text-sm text-base-content/60">
                                {{ $transaction->created_at->format('d M Y H:i') }} — {{ $transaction->user?->name }}
                            </div>
                            <div class="text-sm text-base-content/60">
                                {{ $transaction->customer?->name ?? 'Pelanggan Umum' }} ·
                                {{ $transaction->payments->pluck('method')->implode('/') }}
                            </div>
                        </div>
                        <span class="badge {{ $transaction->isVoided() ? 'badge-error' : 'badge-success' }}">
                            {{ $transaction->isVoided() ? 'VOID' : 'OK' }}
                        </span>
                    </div>

                    <div class="mt-2 font-bold text-lg">@money($transaction->total)</div>

                    <div class="card-actions justify-end mt-3">
                        <button onclick="showReceipt('{{ route('transactions.receipt', $transaction) }}')"
                                class="btn btn-sm btn-outline min-h-[44px]">🖨 Struk</button>

                        @can('void', $transaction)
                            @unless($transaction->isVoided())
                                <form method="POST" action="{{ route('transactions.void', $transaction) }}"
                                      onsubmit="swalConfirm({ title: this.dataset.title, text: this.dataset.text, url: this.action, method: 'POST' }); return false;" data-title="Void transaksi {{ $transaction->invoice_no }}?" data-text="Stok akan dikembalikan.">
                                    @csrf
                                    <input type="hidden" name="reason" value="Dibatalkan dari halaman riwayat">
                                    <button type="submit" class="btn btn-sm btn-error text-white min-h-[44px]">Void</button>
                                </form>
                            @endunless
                        @endcan
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full text-center text-base-content/50 mt-10">Belum ada transaksi.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>

    {{-- Modal struk --}}
    <div id="receipt-modal" class="fixed inset-0 z-50 items-center justify-center" style="display: none;">
        <div class="absolute inset-0 bg-base-300/70" onclick="hideReceipt()"></div>
        <div class="relative bg-base-100 rounded-xl shadow-2xl max-h-[90vh] overflow-y-auto p-3">
            <div id="receipt-content" class="receipt-content"></div>
            <div class="flex gap-2 mt-2 print:hidden">
                <button onclick="window.print()" class="btn btn-primary flex-1 min-h-[48px]">🖨 Cetak</button>
                <button onclick="hideReceipt()" class="btn btn-ghost flex-1 min-h-[48px]">Tutup</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    async function showReceipt(url) {
        const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        document.getElementById('receipt-content').innerHTML = await response.text();
        const modal = document.getElementById('receipt-modal');
        modal.style.display = 'flex';
    }

    function hideReceipt() {
        document.getElementById('receipt-modal').style.display = 'none';
    }
</script>
@endpush
