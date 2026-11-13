@extends('layouts.app')

@section('content')
    {{--
        Satu form untuk SEMUA tab, bukan satu form per tab.

        Kalau tiap tab punya form sendiri, berpindah tab menghapus isian tab
        sebelumnya dan admin akan kehilangan pekerjaannya tanpa sadar. Panel
        yang tersembunyi tetap ikut terkirim saat disimpan — hanya disembunyikan
        secara visual oleh x-show.
    --}}
    <div x-data="settingsPage(@js(array_replace($values, session()->getOldInput())), @js(config('currencies')), @js(route('settings.exchange-rate')))" class="max-w-4xl">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold">Pengaturan Toko</h1>
        </div>

        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
            @csrf @method('PUT')

            {{-- Tab bar --}}
            <div role="tablist" class="tabs tabs-boxed mb-6 bg-base-200 p-1">
                @foreach($groups as $groupKey => $groupLabel)
                    <button type="button" role="tab"
                            @click="activeTab = '{{ $groupKey }}'"
                            :class="activeTab === '{{ $groupKey }}' ? 'tab-active' : ''"
                            class="tab min-h-[48px] text-sm font-medium">
                        {{ $groupLabel }}
                    </button>
                @endforeach
            </div>

            @foreach($groups as $groupKey => $groupLabel)
                <div x-show="activeTab === '{{ $groupKey }}'" x-cloak class="card bg-base-100 shadow-sm">
                    <div class="card-body p-5 space-y-4">

                        @foreach($items as $key => $config)
                            @continue($config['group'] !== $groupKey)
                            @continue($key === 'company_logo') {{-- ditangani khusus di bawah --}}

                            <div>
                                @if($config['type'] !== 'checkbox')
                                    <label class="block text-sm font-medium mb-1" for="{{ $key }}">
                                        {{ $config['label'] }}
                                    </label>
                                @endif

                                @if($config['type'] === 'currency')
                                    <select id="{{ $key }}" name="{{ $key }}" x-model="form.currency_symbol"
                                            @change="changeCurrency()" class="select select-bordered w-full h-12">
                                        @foreach(config('currencies') as $symbol => $currency)
                                            <option value="{{ $symbol }}">{{ $currency['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-xs text-base-content/60 mt-2">Nominal tampilan dikonversi otomatis. Harga, input pembayaran, dan data transaksi tetap dalam rupiah (IDR).</p>
                                @elseif($config['type'] === 'textarea')
                                    <textarea id="{{ $key }}" name="{{ $key }}" rows="2"
                                              class="textarea textarea-bordered w-full min-h-[56px]">{{ old($key, $values[$key] ?? '') }}</textarea>

                                @elseif($config['type'] === 'select')
                                    <select id="{{ $key }}" name="{{ $key }}" x-model="form.{{ $key }}" class="select select-bordered w-full h-12">
                                        <option value="left" @selected(old($key, $values[$key] ?? '') === 'left')>Di depan (Rp 1.234)</option>
                                        <option value="right" @selected(old($key, $values[$key] ?? '') === 'right')>Di belakang (1.234 Rp)</option>
                                    </select>

                                @elseif($config['type'] === 'checkbox')
                                    {{--
                                        Hidden input "0" WAJIB didahului checkbox.
                                        Tanpa ini, saat checkbox tidak dicentang
                                        browser tidak mengirim apa pun, validasi
                                        boolean melewatkannya, dan nilai lama tidak
                                        pernah bisa dimatikan dari UI.
                                    --}}
                                    <input type="hidden" name="{{ $key }}" value="0">
                                    <label class="flex items-center gap-3 min-h-[48px] cursor-pointer">
                                        <input type="checkbox" name="{{ $key }}" value="1"
                                               @checked(old($key, $values[$key] ?? false))
                                               class="checkbox checkbox-primary w-6 h-6">
                                        <span class="text-sm font-medium">{{ $config['label'] }}</span>
                                    </label>

                                @else
                                    <input type="{{ $config['type'] }}" id="{{ $key }}" name="{{ $key }}"
                                           value="{{ old($key, $values[$key] ?? '') }}"
                                           @if(str_starts_with($key, 'currency_')) x-model="form.{{ $key }}" @endif
                                           @if(in_array($config['type'], ['number'], true)) step="any" min="0" @endif
                                           class="input input-bordered w-full h-12">
                                @endif

                                @error($key)
                                    <p class="text-error text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Pratinjau langsung format mata uang --}}
                            @if($key === 'currency_decimal_separator')
                                <div class="alert alert-info">
                                    <div>
                                        <div class="text-xs opacity-70">Pratinjau konversi dari Rp 1.234.567</div>
                                        <div class="font-mono text-lg" x-text="moneyPreview()"></div>
                                        <p class="text-xs mt-1" x-show="rateDate" x-text="'Kurs Frankfurter • ' + rateDate + (stale ? ' • Memakai kurs tersimpan; koneksi sedang terganggu.' : '')"></p>
                                        <p class="text-sm text-error" x-show="rateError" x-text="rateError"></p>
                                        <button type="button" x-show="rateError" @click="loadRate()" class="btn btn-sm min-h-[44px] mt-2">Coba ambil kurs lagi</button>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        {{-- Logo: ditangani terpisah karena berupa berkas --}}
                        @if($groupKey === 'information')
                            <div>
                                <label class="block text-sm font-medium mb-1">Logo Toko</label>

                                @php($logo = $values['company_logo'] ?? '')

                                <div class="flex items-center gap-4">
                                    @if($logo !== '')
                                        <img src="{{ asset('storage/'.$logo) }}"
                                             alt="Logo" class="w-20 h-20 object-contain rounded bg-base-200">
                                    @endif

                                    <input type="file" name="logo" accept="image/*"
                                           class="file-input file-input-bordered w-full h-12">
                                </div>

                                @if($logo !== '')
                                    <label class="flex items-center gap-3 min-h-[48px] cursor-pointer mt-1">
                                        <input type="checkbox" name="remove_logo" value="1"
                                               @checked(old('remove_logo')) class="checkbox checkbox-error w-6 h-6">
                                        <span class="text-sm">Hapus logo saat ini</span>
                                    </label>
                                @endif

                                <p class="text-xs text-base-content/60 mt-1">
                                    Maksimal 2 MB. Logo tampil di struk cetak.
                                </p>

                                @error('logo')
                                    <p class="text-error text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        <div class="card-actions justify-end pt-2">
                            <button type="submit" :disabled="rateLoading || !!rateError" class="btn btn-primary min-h-[48px] px-8">
                                Simpan Pengaturan
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </form>

        @if($errors->any())
            <div class="alert alert-error mt-4">
                <span>Ada isian yang belum valid. Periksa merah di bawah tiap kolom.</span>
            </div>
        @endif
    </div>

    <script>
        function settingsPage(values, currencies, rateUrl) {
            return {
                activeTab: 'information',
                form: Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v ?? ''])),
                rate: null,
                previewAmount: null,
                rateDate: '',
                stale: false,
                rateLoading: false,
                rateError: '',
                requestId: 0,

                init() {
                    this.loadRate();
                },

                changeCurrency() {
                    const currency = currencies[this.form.currency_symbol];
                    this.form.currency_decimals = currency.decimals;
                    this.loadRate();
                },

                async loadRate() {
                    const requestId = ++this.requestId;
                    const code = currencies[this.form.currency_symbol]?.code ?? 'IDR';
                    this.rate = null;
                    this.previewAmount = null;
                    this.rateDate = '';
                    this.rateError = '';
                    this.stale = false;
                    this.rateLoading = code !== 'IDR';
                    if (code === 'IDR') {
                        this.rate = 1;
                        this.previewAmount = 1234567;
                        return;
                    }
                    try {
                        const response = await fetch(rateUrl + '?currency=' + encodeURIComponent(code), {
                            headers: { Accept: 'application/json' },
                        });
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'Kurs gagal dimuat.');
                        if (requestId !== this.requestId) return;
                        this.rate = data.rate;
                        this.previewAmount = data.sample_amount;
                        this.rateDate = data.date;
                        this.stale = data.stale;
                    } catch (error) {
                        if (requestId === this.requestId) this.rateError = 'Kurs gagal dimuat. Periksa koneksi lalu coba lagi.';
                    } finally {
                        if (requestId === this.requestId) this.rateLoading = false;
                    }
                },

                /**
                 * Meniru number_format($x, 0, ',', '.') dari PHP — sengaja
                 * ditulis sama agar admin melihat bentuk angka yang persis
                 * akan muncul di laporan dan struk.
                 */
                moneyPreview() {
                    if (this.rateLoading) return 'Mengambil kurs…';
                    if (this.rate === null) return 'Konversi belum tersedia';
                    const amount = this.previewAmount;
                    const decimals = Math.min(4, Math.max(0, parseInt(this.form['currency_decimals'] ?? 0, 10) || 0));
                    const thousands = this.form['currency_thousands_separator'] ?? '.';
                    const decimal = this.form['currency_decimal_separator'] ?? ',';
                    const symbol = this.form['currency_symbol'] ?? 'Rp';
                    const position = this.form['currency_position'] ?? 'left';

                    const parts = Math.abs(amount).toFixed(decimals).split('.');
                    const grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
                    const number = parts[1] ? grouped + decimal + parts[1] : grouped;

                    return position === 'right'
                        ? number + ' ' + symbol
                        : symbol + ' ' + number;
                },
            };
        }
    </script>
@endsection
