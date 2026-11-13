@php
    $displaySymbol = app(\App\Services\SettingService::class)->get('currency_symbol', 'Rp');
    $displayCurrency = config('currencies')[$displaySymbol]['code'] ?? 'IDR';
    $displayQuote = null;
    if ($displayCurrency !== 'IDR') {
        try {
            $displayQuote = app(\App\Services\ExchangeRateService::class)->quote($displayCurrency);
        } catch (\RuntimeException) {
            // Formatter kembali menampilkan IDR jika tidak ada kurs tersimpan.
        }
    }
@endphp
@if($displayCurrency !== 'IDR')
    <div class="px-4 py-2 text-sm {{ $displayQuote === null || $displayQuote['stale'] ? 'bg-warning/20' : 'bg-info/10' }}">
        @if($displayQuote === null)
            Kurs {{ $displayCurrency }} tidak tersedia. Nominal sementara ditampilkan dalam rupiah (IDR).
        @else
            Tampilan {{ $displayCurrency }} • Kurs Frankfurter {{ $displayQuote['date'] }}.
            @if($displayQuote['stale']) Kurs tersimpan digunakan karena koneksi terganggu. @endif
            Input harga, diskon, dan pembayaran tetap dalam rupiah (IDR).
        @endif
    </div>
@endif
