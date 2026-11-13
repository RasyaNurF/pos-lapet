<div class="receipt bg-white text-black p-2 font-mono text-xs" style="width: 80mm;">
    @php($store = app(\App\Services\SettingService::class)->all())

    <div class="text-center mb-2">
        @if($store['company_logo'] !== '')
            <img src="{{ asset('storage/'.$store['company_logo']) }}" alt="Logo {{ $store['company_name'] }}"
                 width="64" height="64" class="mx-auto mb-2"
                 style="width: 64px; height: 64px; object-fit: contain;">
        @endif
        <div class="font-bold text-sm">{{ $store['company_name'] }}</div>
        @if($store['company_address'] !== '')
            <div class="whitespace-pre-line">{{ $store['company_address'] }}</div>
        @endif
        @foreach(['company_phone', 'company_email', 'company_website'] as $field)
            @if($store[$field] !== '')
                <div class="break-words">{{ $store[$field] }}</div>
            @endif
        @endforeach
    </div>

    <div class="border-t border-dashed border-black my-1"></div>

    <div class="flex justify-between">
        <span>No.</span><span>{{ $transaction->invoice_no }}</span>
    </div>
    <div class="flex justify-between">
        <span>Tanggal</span><span>{{ $transaction->created_at->format('d/m/Y H:i') }}</span>
    </div>
    <div class="flex justify-between">
        <span>Kasir</span><span>{{ $transaction->user?->name }}</span>
    </div>
    <div class="flex justify-between">
        <span>Pelanggan</span><span>{{ $transaction->customer?->name ?? 'Umum' }}</span>
    </div>

    <div class="border-t border-dashed border-black my-1"></div>

    @foreach($transaction->items as $item)
        <div>{{ $item->product_name }}</div>
        <div class="flex justify-between">
            <span>{{ $item->qty }} x @money($item->price)</span>
            <span>@money($item->subtotal)</span>
        </div>
    @endforeach

    <div class="border-t border-dashed border-black my-1"></div>

    <div class="flex justify-between"><span>Subtotal</span><span>@money($transaction->subtotal)</span></div>
    @if($transaction->discount > 0)
        <div class="flex justify-between"><span>Diskon</span><span>-@money($transaction->discount)</span></div>
    @endif
    <div class="flex justify-between"><span>Pajak ({{ $transaction->tax_rate_label }}%)</span><span>@money($transaction->tax)</span></div>
    <div class="flex justify-between font-bold text-sm"><span>TOTAL</span><span>@money($transaction->total)</span></div>
    @if($transaction->customer && $transaction->points_earned > 0)
        <div class="flex justify-between"><span>Poin transaksi ini</span><span>+{{ $transaction->points_earned }}</span></div>
    @endif

    <div class="border-t border-dashed border-black my-1"></div>

    @foreach($transaction->payments as $payment)
        <div class="flex justify-between">
            <span>{{ strtoupper($payment->method) }}</span>
            <span>@money($payment->amount)</span>
        </div>
        @if($payment->reference_no)
            <div>Ref: {{ $payment->reference_no }}</div>
        @endif
    @endforeach

    @if($transaction->isVoided())
        <div class="text-center font-bold text-sm mt-2 border border-black p-1">*** VOID ***</div>
    @endif

    <div class="text-center mt-3">Terima kasih sudah berbelanja!</div>
</div>

<style>
    @media print {
        @page { size: 80mm auto; margin: 2mm; }
        body * { visibility: hidden; }
        .receipt, .receipt * { visibility: visible; }
        .receipt { position: absolute; left: 0; top: 0; width: 80mm; }
    }
</style>
