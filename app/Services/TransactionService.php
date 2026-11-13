<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\SuspendedTransaction;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TransactionService
{
    /** Metode pembayaran yang sah. */
    private const array ALLOWED_METHODS = [
        TransactionPayment::METHOD_CASH,
        TransactionPayment::METHOD_QRIS,
        TransactionPayment::METHOD_BANK,
        TransactionPayment::METHOD_CREDIT_CARD,
        TransactionPayment::METHOD_DANA,
        TransactionPayment::METHOD_GOPAY,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SaleCalculator $calculator,
    ) {}

    /**
     * Proses checkout: simpan header, item (snapshot), pembayaran, potong stok.
     *
     * Semua kalkulasi & potong stok dilakukan di backend (AGENTS.md §3).
     * Input dari frontend **tidak pernah dipercaya** — divalidasi ulang di sini
     * (Layer 1 Input Guard), karena komponen/klien bisa dimanipulasi.
     *
     * @param  array<int, array{id: int|string, name?: string, price?: int, qty?: int|string}>  $cart
     */
    public function processTransaction(
        User $user,
        array $cart,
        ?int $customerId,
        string $paymentMethod,
        int $amountTendered,
        int $discount = 0,
        ?string $clientToken = null,
        ?int $splitPrimaryAmount = null,
        ?string $secondPaymentMethod = null,
        string $qrisReference = '',
    ): Transaction {
        if ($cart === []) {
            throw new RuntimeException('Keranjang kosong.');
        }

        // --- Layer 1 Input Guard: jangan percaya frontend -------------------
        if (! in_array($paymentMethod, self::ALLOWED_METHODS, true)) {
            throw new RuntimeException('Metode pembayaran tidak dikenal.');
        }

        if ($amountTendered < 0) {
            throw new RuntimeException('Uang diterima tidak boleh negatif.');
        }

        if ($discount < 0) {
            $discount = 0;
        }

        // --- Layer 4 Data Guard — idempotency -------------------------------
        // Klik "Bayar" dua kali (atau retry jaringan) mengirim token yang sama.
        // Transaksi dengan token itu sudah ada → jangan diproses ulang.
        if ($clientToken !== null && ($existing = $this->findByClientToken($clientToken)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $cart, $customerId, $paymentMethod, $amountTendered, $discount, $clientToken, $splitPrimaryAmount, $secondPaymentMethod, $qrisReference): Transaction {
                // Lock pelanggan terdaftar selama checkout; pelanggan anonim tidak memperoleh poin.
                $customer = $customerId === null
                    ? null
                    : Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();

                // Hitung ulang total di backend — jangan percaya frontend (AGENTS.md §3).
                $subtotal = 0;
                $items = [];

                foreach ($cart as $item) {
                    $qty = (int) ($item['qty'] ?? 0);

                    // Layer 1: qty harus bilangan bulat positif — tolak qty negatif
                    // yang bisa membalik arah potong stok menjadi penambahan.
                    if ($qty < 1) {
                        throw new RuntimeException('Jumlah item tidak valid.');
                    }

                    // Lock baris variant agar aman dari race condition dua kasir (ADR-0003).
                    $variant = ProductVariant::query()
                        ->whereKey($item['id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($variant->product?->is_active !== true) {
                        throw new RuntimeException('Produk sudah tidak aktif atau dihapus. Hapus produk tersebut dari keranjang sebelum membayar.');
                    }

                    if ($variant->stock < $qty) {
                        throw new RuntimeException("Stok \"{$variant->display_name}\" tidak mencukupi.");
                    }

                    $lineSubtotal = $variant->price * $qty;
                    $subtotal += $lineSubtotal;

                    $items[] = [
                        'variant' => $variant,
                        'price' => $variant->price,
                        'cost' => $variant->cost,
                        'qty' => $qty,
                        'subtotal' => $lineSubtotal,
                    ];
                }

                // Layer 1: batasi diskon maksimal subtotal (total tidak pernah negatif).
                $discount = min($discount, $subtotal);

                /*
                 * PPN & total diambil dari SATU kalkulator yang juga dipakai
                 * preview POS. Dua rumus terpisah = suatu saat pasti berbeda,
                 * dan selisihnya langsung jadi kerugian uang nyata.
                 */
                $taxRate = $this->calculator->taxRate();
                $tax = $this->calculator->taxFor($subtotal);
                $total = $this->calculator->totalFor($subtotal, $tax, $discount);
                $pointsEarned = $customer === null ? 0 : intdiv($total, 10000);

                if ($splitPrimaryAmount !== null && ($splitPrimaryAmount < 0 || $splitPrimaryAmount > $total)) {
                    throw new RuntimeException('Nominal pembayaran pertama tidak valid.');
                }
                if ($splitPrimaryAmount !== null && (! in_array($secondPaymentMethod, self::ALLOWED_METHODS, true) || $secondPaymentMethod === $paymentMethod)) {
                    throw new RuntimeException('Metode pembayaran kedua tidak valid.');
                }
                $primaryAmount = $splitPrimaryAmount ?? $total;
                $secondaryAmount = $splitPrimaryAmount !== null ? $total - $primaryAmount : 0;

                if ($splitPrimaryAmount === null && $paymentMethod === TransactionPayment::METHOD_CASH && $amountTendered < $total) {
                    throw new RuntimeException('Uang diterima kurang dari total belanja.');
                }

                // amount pembayaran tetap nilai yang melunasi tagihan (neto),
                // bukan uang diserahkan pelanggan yang mungkin perlu dikembalikan.
                $cashTendered = null;
                $changeAmount = null;
                if ($splitPrimaryAmount === null && $paymentMethod === TransactionPayment::METHOD_CASH) {
                    $cashTendered = $amountTendered;
                    $changeAmount = $this->calculateChange($amountTendered, $total);
                } elseif ($splitPrimaryAmount !== null) {
                    // Input split saat ini adalah alokasi pembayaran pertama;
                    // kedua alokasi dianggap dibayar pas, bukan uang tender.
                    if ($paymentMethod === TransactionPayment::METHOD_CASH && $primaryAmount > 0) {
                        $cashTendered = $primaryAmount;
                        $changeAmount = 0;
                    } elseif ($secondPaymentMethod === TransactionPayment::METHOD_CASH && $secondaryAmount > 0) {
                        $cashTendered = $secondaryAmount;
                        $changeAmount = 0;
                    }
                }
                $transaction = Transaction::create([
                    'invoice_no' => $this->generateInvoiceNo(),
                    'client_token' => $clientToken,
                    'customer_id' => $customerId,
                    'user_id' => $user->id,
                    'shift_id' => $user->currentShift?->id,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'tax' => $tax,
                    // Snapshot tarif berlaku: mengubah pengaturan PPN nanti tidak
                    // mengubah riwayat struk lama.
                    'tax_rate' => $taxRate,
                    'total' => $total,
                    'cash_tendered' => $cashTendered,
                    'change_amount' => $changeAmount,
                    'points_earned' => $pointsEarned,
                    'status' => Transaction::STATUS_COMPLETED,
                ]);

                foreach ($items as $line) {
                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'variant_id' => $line['variant']->id,
                        'product_name' => $line['variant']->display_name, // snapshot
                        'price' => $line['price'],
                        'cost' => $line['cost'],
                        'qty' => $line['qty'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    $line['variant']->decrement('stock', $line['qty']);
                }

                if ($primaryAmount > 0) {
                    TransactionPayment::create([
                        'transaction_id' => $transaction->id,
                        'method' => $paymentMethod,
                        'amount' => $primaryAmount,
                        'reference_no' => $paymentMethod === TransactionPayment::METHOD_CASH
                            ? null
                            : (trim($qrisReference) !== '' ? trim($qrisReference) : ($paymentMethod === TransactionPayment::METHOD_QRIS ? 'QRIS-'.Str::upper(Str::random(8)) : null)),
                    ]);
                }
                if ($secondaryAmount > 0) {
                    TransactionPayment::create([
                        'transaction_id' => $transaction->id,
                        'method' => $secondPaymentMethod,
                        'amount' => $secondaryAmount,
                        'reference_no' => $secondPaymentMethod === TransactionPayment::METHOD_QRIS
                            ? (trim($qrisReference) !== '' ? trim($qrisReference) : 'QRIS-'.Str::upper(Str::random(8)))
                            : null,
                    ]);
                }

                if ($customer !== null && $pointsEarned > 0) {
                    $customer->increment('points', $pointsEarned);
                }

                // Update total sales shift berjalan.
                $user->currentShift?->increment('total_sales', $total);

                return $transaction->load(['items', 'payments', 'customer']);
            });
        } catch (QueryException $e) {
            // Balapan dua request dengan token sama: UNIQUE(client_token) menang
            // di database. Transaksi pertama menang, yang kedua dikembalikan apa
            // adanya (tanpa memotong stok dua kali).
            if ($clientToken !== null && ($existing = $this->findByClientToken($clientToken)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Cari transaksi berdasarkan token idempotency (null bila tidak ada).
     */
    private function findByClientToken(string $clientToken): ?Transaction
    {
        return Transaction::query()
            ->where('client_token', $clientToken)
            ->with(['items', 'payments', 'customer'])
            ->first();
    }

    /**
     * Void transaksi: tandai status & kembalikan stok (AGENTS.md §7).
     *
     * Baris transaksi dikunci `lockForUpdate` — dua klik void bersamaan tidak
     * bisa sama-sama lolos cek status (stok tidak terkembalikan dua kali).
     */
    public function voidTransaction(Transaction $transaction, User $by, ?string $reason = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $by, $reason): Transaction {
            $locked = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isVoided()) {
                throw new RuntimeException('Transaksi sudah pernah di-void.');
            }

            foreach ($locked->items as $item) {
                if ($item->variant_id === null) {
                    continue;
                }

                ProductVariant::query()
                    ->whereKey($item->variant_id)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('stock', $item->qty);
            }

            if ($locked->customer_id !== null && $locked->points_earned > 0) {
                $customer = Customer::withTrashed()
                    ->whereKey($locked->customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($customer !== null) {
                    $customer->decrement('points', min($customer->points, $locked->points_earned));
                }
            }

            $locked->update([
                'status' => Transaction::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $by->id,
                'notes' => $reason ?? $locked->notes,
            ]);

            // Kurangi penjualan shift dan kunci baris agar konsisten dengan
            // proses tutup shift yang mungkin berjalan bersamaan.
            $shift = $locked->shift_id !== null
                ? Shift::query()->whereKey($locked->shift_id)->lockForUpdate()->first()
                : null;

            if ($shift !== null) {
                $shift->decrement('total_sales', $locked->total);

                // Bila shift sudah direkonsiliasi, transaksi tunai yang baru
                // di-void mengubah kas yang seharusnya dan selisih akhirnya.
                // Pembayaran non-tunai mengurangi total sales saja.
                if ($shift->end_time !== null && $shift->end_cash !== null) {
                    $cashSales = (int) TransactionPayment::query()
                        ->where('method', TransactionPayment::METHOD_CASH)
                        ->whereHas('transaction', fn ($query) => $query
                            ->where('shift_id', $shift->id)
                            ->where('status', Transaction::STATUS_COMPLETED))
                        ->sum('amount');

                    $expectedCash = (int) $shift->start_cash + $cashSales;

                    $shift->update([
                        'expected_cash' => $expectedCash,
                        'difference' => (int) $shift->end_cash - $expectedCash,
                    ]);
                }
            }

            $this->audit->log(
                'transaction.void',
                $locked,
                ['status' => Transaction::STATUS_COMPLETED],
                ['status' => Transaction::STATUS_VOIDED, 'reason' => $reason],
                $by,
            );

            return $locked->refresh();
        });
    }

    /**
     * Hitung kembalian (dihitung ulang di backend).
     */
    public function calculateChange(int $amountTendered, int $total): int
    {
        return max(0, $amountTendered - $total);
    }

    /**
     * Suspend (hold) keranjang ke database (ADR-0004).
     *
     * @param  array<int, array{id: int, name: string, price: int, qty: int}>  $cart
     */
    public function suspend(User $user, array $cart, ?int $customerId, int $discount = 0, ?string $label = null): SuspendedTransaction
    {
        if ($cart === []) {
            throw new RuntimeException('Keranjang kosong, tidak perlu disuspend.');
        }

        return SuspendedTransaction::create([
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'cart' => array_values($cart),
            'discount' => $discount,
            'label' => $label,
        ]);
    }

    /**
     * Resume keranjang dari suspend, lalu hapus record suspend.
     *
     * @return array<int, array{id: int, name: string, price: int, qty: int}>
     */
    public function resume(SuspendedTransaction $suspended): array
    {
        $cart = collect($suspended->cart)->keyBy('id')->all();

        $suspended->delete();

        return $cart;
    }

    /**
     * Format nomor invoice: INV-YYYYMMDD-XXXX (urut per hari).
     *
     * Layer 4 Data Guard — baca + tulis counter dalam satu kunci `lockForUpdate`,
     * sehingga dua kasir yang checkout bersamaan tidak pernah mendapat nomor sama.
     */
    private function generateInvoiceNo(): string
    {
        $day = now()->format('Ymd');
        $prefix = 'INV-'.$day.'-';

        // Buat baris counter hari ini bila belum ada (insertOrIgnore = idempotent).
        DB::table('invoice_counters')->insertOrIgnore([
            'day' => $day,
            'sequence' => 0,
        ]);

        // Kunci baris counter → serialisasi pembacaan nomor antar transaksi.
        $sequence = (int) DB::table('invoice_counters')
            ->where('day', $day)
            ->lockForUpdate()
            ->value('sequence');

        $sequence++;

        DB::table('invoice_counters')
            ->where('day', $day)
            ->update(['sequence' => $sequence]);

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
