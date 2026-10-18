<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\SuspendedTransaction;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TransactionService
{
    /** Metode pembayaran yang sah. */
    private const array ALLOWED_METHODS = [
        TransactionPayment::METHOD_CASH,
        TransactionPayment::METHOD_QRIS,
    ];

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

        return DB::transaction(function () use ($user, $cart, $customerId, $paymentMethod, $amountTendered, $discount): Transaction {
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

            $tax = (int) round($subtotal * 0.11); // PPN 11%
            $total = max(0, $subtotal + $tax - $discount);

            if ($paymentMethod === TransactionPayment::METHOD_CASH && $amountTendered < $total) {
                throw new RuntimeException('Uang diterima kurang dari total belanja.');
            }

            $transaction = Transaction::create([
                'invoice_no' => $this->generateInvoiceNo(),
                'customer_id' => $customerId,
                'user_id' => $user->id,
                'shift_id' => $user->currentShift?->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
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

            TransactionPayment::create([
                'transaction_id' => $transaction->id,
                'method' => $paymentMethod,
                'amount' => $total,
                'reference_no' => $paymentMethod === TransactionPayment::METHOD_QRIS
                    ? 'QRIS-'.Str::upper(Str::random(8))
                    : null,
            ]);

            // Update total sales shift berjalan.
            $user->currentShift?->increment('total_sales', $total);

            return $transaction->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Void transaksi: tandai status & kembalikan stok (AGENTS.md §7).
     */
    public function voidTransaction(Transaction $transaction, User $by, ?string $reason = null): Transaction
    {
        if ($transaction->isVoided()) {
            throw new RuntimeException('Transaksi sudah pernah di-void.');
        }

        return DB::transaction(function () use ($transaction, $by, $reason): Transaction {
            foreach ($transaction->items as $item) {
                if ($item->variant_id === null) {
                    continue;
                }

                ProductVariant::query()
                    ->whereKey($item->variant_id)
                    ->lockForUpdate()
                    ->first()
                    ?->increment('stock', $item->qty);
            }

            $transaction->update([
                'status' => Transaction::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by' => $by->id,
                'notes' => $reason ?? $transaction->notes,
            ]);

            // Kurangi total sales shift jika shift masih terbuka.
            $transaction->shift?->decrement('total_sales', $transaction->total);

            return $transaction->refresh();
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
     */
    private function generateInvoiceNo(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $lastNumber = Transaction::query()
            ->where('invoice_no', 'like', $prefix.'%')
            ->max('invoice_no');

        $sequence = $lastNumber !== null
            ? ((int) substr($lastNumber, -4)) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
