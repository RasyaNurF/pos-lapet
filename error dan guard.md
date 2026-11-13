# Full Schema — 7 Guard POS 1 Outlet Multi-Kasir (Revisi dengan Tendered/Applied/Change)

PostgreSQL. Diurutkan mengikuti dependency FK. Semua constraint dan unique index yang menjadi "guard" ditulis eksplisit.

---

## 0. Tenancy & Identity (fondasi minimal)

```sql
CREATE TABLE outlets (
  id         BIGSERIAL PRIMARY KEY,
  code       TEXT NOT NULL UNIQUE,
  name       TEXT NOT NULL,
  timezone   TEXT NOT NULL DEFAULT 'Asia/Jakarta',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE terminals (
  id         BIGSERIAL PRIMARY KEY,
  outlet_id  BIGINT NOT NULL REFERENCES outlets(id),
  code       TEXT NOT NULL,
  is_active  BOOLEAN NOT NULL DEFAULT true,
  UNIQUE (outlet_id, code),
  UNIQUE (id, outlet_id)          -- untuk composite FK
);

CREATE TABLE staff (
  id         BIGSERIAL PRIMARY KEY,
  outlet_id  BIGINT REFERENCES outlets(id),
  username   TEXT NOT NULL UNIQUE,
  role       TEXT NOT NULL CHECK (role IN ('cashier','supervisor','manager')),
  is_active  BOOLEAN NOT NULL DEFAULT true,
  UNIQUE (id, outlet_id)
);
```

---

## Guard 1 — Idempotency (generic untuk endpoint non-sale)

```sql
CREATE TABLE idempotency_keys (
  key             UUID PRIMARY KEY,
  endpoint        TEXT NOT NULL,
  request_hash    TEXT NOT NULL,
  response_status INT,
  response_body   JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Idempotency per-entitas (defense in depth) diletakkan sebagai kolom + unique index di tabel yang relevan:
- `sales.client_request_id`
- `payments.client_request_id`
- `cash_movements.client_request_id`
- `stock_movements.client_request_id`

---

## Guard 6 — Invoice Numbering (transaksional)

```sql
CREATE TABLE invoice_sequences (
  outlet_id   BIGINT NOT NULL REFERENCES outlets(id),
  terminal_id BIGINT NOT NULL REFERENCES terminals(id),
  period      TEXT NOT NULL,                 -- '2026-10'
  last_number BIGINT NOT NULL DEFAULT 0 CHECK (last_number >= 0),
  PRIMARY KEY (outlet_id, terminal_id, period)
);
```

Nomor diambil di transaksi yang sama dengan insert sale, **hanya jika lolos idempotency**, sehingga retry tidak membakar nomor.

---

## Guard 5 — Shift & Cash Drawer

```sql
CREATE TABLE shifts (
  id             BIGSERIAL PRIMARY KEY,
  outlet_id      BIGINT NOT NULL REFERENCES outlets(id),
  terminal_id    BIGINT NOT NULL,
  cashier_id     BIGINT NOT NULL REFERENCES staff(id),
  status         TEXT NOT NULL CHECK (status IN ('open','closed','force_closed')),
  opening_float  NUMERIC(14,2) NOT NULL CHECK (opening_float >= 0),
  expected_cash  NUMERIC(14,2),
  counted_cash   NUMERIC(14,2),
  variance       NUMERIC(14,2),
  opened_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  closed_at      TIMESTAMPTZ,
  closed_by      BIGINT REFERENCES staff(id),
  close_reason   TEXT,
  FOREIGN KEY (terminal_id, outlet_id) REFERENCES terminals(id, outlet_id),
  UNIQUE (id, terminal_id),          -- untuk composite FK dari sales
  UNIQUE (id, outlet_id)
);

-- satu terminal = satu shift open
CREATE UNIQUE INDEX ux_shift_open_per_terminal
  ON shifts(terminal_id) WHERE status='open';

CREATE TABLE cash_movements (
  id                BIGSERIAL PRIMARY KEY,
  shift_id          BIGINT NOT NULL REFERENCES shifts(id),
  type              TEXT NOT NULL CHECK (type IN (
                      'cash_in','cash_out','sale_cash','change_out','refund_cash')),
  amount            NUMERIC(14,2) NOT NULL,           -- + masuk, − keluar
  ref_type          TEXT,
  ref_id            BIGINT,
  client_request_id UUID,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ux_cash_mv_client_request
  ON cash_movements(client_request_id) WHERE client_request_id IS NOT NULL;
CREATE INDEX ix_cash_mv_shift ON cash_movements(shift_id);
```

`opening_float` disimpan di `shifts`, **tidak** diduplikasi di `cash_movements`, agar tidak double-count.

---

## Produk & Inventory (prasyarat Guard 4)

```sql
CREATE TABLE products (
  id        BIGSERIAL PRIMARY KEY,
  sku       TEXT NOT NULL UNIQUE,
  name      TEXT NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE inventory (
  outlet_id    BIGINT NOT NULL REFERENCES outlets(id),
  product_id   BIGINT NOT NULL REFERENCES products(id),
  qty_on_hand  NUMERIC(14,3) NOT NULL DEFAULT 0 CHECK (qty_on_hand >= 0),
  qty_reserved NUMERIC(14,3) NOT NULL DEFAULT 0 CHECK (qty_reserved >= 0),
  version      INT NOT NULL DEFAULT 0,
  PRIMARY KEY (outlet_id, product_id),
  CHECK (qty_on_hand - qty_reserved >= 0)
);
```

---

## Sales (Guard 1, 3, 6 bertemu di sini)

```sql
CREATE TABLE sales (
  id               BIGSERIAL PRIMARY KEY,
  outlet_id        BIGINT NOT NULL,
  terminal_id      BIGINT NOT NULL,
  shift_id         BIGINT NOT NULL,
  cashier_id       BIGINT NOT NULL REFERENCES staff(id),

  invoice_no       TEXT NOT NULL,

  -- Guard 1
  client_request_id UUID NOT NULL,
  request_hash      TEXT NOT NULL,

  -- Guard 3
  status TEXT NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','pending_payment','paid','completed',
                      'partially_refunded','refunded','void_pending','voided','cancelled')),
  version INT NOT NULL DEFAULT 0,

  -- Angka
  subtotal       NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
  discount_total NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (discount_total >= 0),
  tax_total      NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (tax_total >= 0),
  total          NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (total >= 0),
  rounding       NUMERIC(14,2) NOT NULL DEFAULT 0,
  payable        NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (payable >= 0),
  CHECK (payable = total + rounding),

  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- Guard 6
  UNIQUE (outlet_id, invoice_no),

  -- Konsistensi tenancy
  FOREIGN KEY (terminal_id, outlet_id) REFERENCES terminals(id, outlet_id),
  FOREIGN KEY (shift_id, terminal_id)  REFERENCES shifts(id, terminal_id)
);

CREATE UNIQUE INDEX ux_sales_client_request ON sales(client_request_id);
CREATE INDEX ix_sales_shift  ON sales(shift_id);
CREATE INDEX ix_sales_status ON sales(status);
```

```sql
CREATE TABLE sale_items (
  id         BIGSERIAL PRIMARY KEY,
  sale_id    BIGINT NOT NULL REFERENCES sales(id) ON DELETE RESTRICT,
  product_id BIGINT NOT NULL REFERENCES products(id),
  qty        NUMERIC(14,3) NOT NULL CHECK (qty > 0),
  unit_price NUMERIC(14,2) NOT NULL CHECK (unit_price >= 0),
  discount   NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
  subtotal   NUMERIC(14,2) NOT NULL CHECK (subtotal >= 0),
  CHECK (subtotal = qty * unit_price - discount)
);
CREATE INDEX ix_sale_items_sale ON sale_items(sale_id);
```

```sql
CREATE TABLE sale_state_log (
  id          BIGSERIAL PRIMARY KEY,
  sale_id     BIGINT NOT NULL REFERENCES sales(id),
  from_status TEXT,
  to_status   TEXT NOT NULL,
  actor_id    BIGINT NOT NULL REFERENCES staff(id),
  reason      TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_state_log_sale ON sale_state_log(sale_id);
```

---

## Guard 2 — Payment Ledger + Tendered/Applied/Change

```sql
CREATE TABLE payments (
  id      BIGSERIAL PRIMARY KEY,
  sale_id BIGINT NOT NULL REFERENCES sales(id),
  shift_id BIGINT NOT NULL REFERENCES shifts(id),

  method    TEXT NOT NULL CHECK (method IN ('cash','qris','debit','credit','ewallet')),
  direction TEXT NOT NULL CHECK (direction IN ('capture','refund')),
  status    TEXT NOT NULL CHECK (status IN ('pending','captured','failed','void')),

  -- tiga angka kunci
  tendered_amount NUMERIC(14,2),                                   -- hanya cash capture
  applied_amount  NUMERIC(14,2) NOT NULL CHECK (applied_amount > 0),
  change_amount   NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (change_amount >= 0),

  gateway_txn_id    TEXT,
  client_request_id UUID,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- Refund: tanpa tendered, tanpa change
  CHECK (direction <> 'refund' OR tendered_amount IS NULL),
  CHECK (direction <> 'refund' OR change_amount = 0),

  -- Cash capture wajib ada tendered dan tendered >= applied
  CHECK (method <> 'cash' OR direction = 'refund' OR tendered_amount IS NOT NULL),
  CHECK (method <> 'cash' OR direction = 'refund' OR tendered_amount >= applied_amount),

  -- Non-cash tidak boleh punya change; tendered kalau ada harus = applied
  CHECK (method = 'cash' OR change_amount = 0),
  CHECK (method = 'cash' OR tendered_amount IS NULL OR tendered_amount = applied_amount),

  -- Rumus change selalu konsisten
  CHECK (change_amount = COALESCE(tendered_amount, applied_amount) - applied_amount),

  -- Cash tidak boleh pending (langsung captured / void)
  CHECK (method <> 'cash' OR status <> 'pending')
);

CREATE UNIQUE INDEX ux_payments_gateway_txn
  ON payments(gateway_txn_id) WHERE gateway_txn_id IS NOT NULL;
CREATE UNIQUE INDEX ux_payments_client_request
  ON payments(client_request_id) WHERE client_request_id IS NOT NULL;
CREATE INDEX ix_payments_sale  ON payments(sale_id);
CREATE INDEX ix_payments_shift ON payments(shift_id);
```

**Invariant Guard 2:**
```
sum(capture.applied) - sum(refund.applied)  ==  sale.payable   → paid/completed
0 < net_applied < payable                                       → partially_refunded
net_applied == 0                                                → refunded
```

`tendered` **tidak** dipakai untuk melunasi bill, tidak dipakai untuk refund, dan tidak masuk expected cash. Hanya `applied` dan `change`.

---

## Guard 4 — Stock Movement Ledger

```sql
CREATE TABLE stock_movements (
  id                BIGSERIAL PRIMARY KEY,
  outlet_id         BIGINT NOT NULL REFERENCES outlets(id),
  product_id        BIGINT NOT NULL REFERENCES products(id),
  type              TEXT NOT NULL CHECK (type IN (
                      'sale','void','refund','adjustment','opname','receive',
                      'reserve','release')),
  qty_delta         NUMERIC(14,3) NOT NULL,
  ref_type          TEXT,
  ref_id            BIGINT,
  shift_id          BIGINT REFERENCES shifts(id),
  client_request_id UUID,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ux_stock_mv_client_request
  ON stock_movements(client_request_id) WHERE client_request_id IS NOT NULL;
CREATE INDEX ix_stock_mv_product ON stock_movements(outlet_id, product_id);
```

Aturan:
- `reserve`/`release` dicatat **audit-only** dengan `qty_delta = 0` (tidak memengaruhi `qty_on_hand`).
- Rekonsiliasi `qty_on_hand` hanya menjumlah movement `type IN ('sale','void','refund','adjustment','opname','receive')`.

---

## Guard 7 — Approval + Audit Log

```sql
CREATE TABLE approvals (
  id           BIGSERIAL PRIMARY KEY,
  sale_id      BIGINT NOT NULL REFERENCES sales(id),
  action       TEXT NOT NULL CHECK (action IN (
                 'refund','void','discount_override','force_close_shift','opname')),
  requested_by BIGINT NOT NULL REFERENCES staff(id),
  approved_by  BIGINT REFERENCES staff(id),
  status       TEXT NOT NULL CHECK (status IN ('pending','approved','rejected')),
  reason       TEXT,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  decided_at   TIMESTAMPTZ,
  -- tidak boleh self-approve
  CHECK (approved_by IS NULL OR approved_by <> requested_by)
);
CREATE INDEX ix_approvals_sale ON approvals(sale_id);

CREATE TABLE audit_log (
  id          BIGSERIAL PRIMARY KEY,
  actor_id    BIGINT NOT NULL REFERENCES staff(id),
  approver_id BIGINT REFERENCES staff(id),
  action      TEXT NOT NULL,             -- 'sale.void', 'payment.refund', 'shift.force_close', ...
  entity_type TEXT NOT NULL,
  entity_id   BIGINT NOT NULL,
  before      JSONB,
  after       JSONB,
  terminal_id BIGINT,
  shift_id    BIGINT,
  sale_id     BIGINT,
  request_id  UUID,
  reason      TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_audit_entity ON audit_log(entity_type, entity_id);
CREATE INDEX ix_audit_time   ON audit_log(created_at);

-- Immutability: role aplikasi tidak boleh ubah/hapus
REVOKE UPDATE, DELETE ON audit_log       FROM app_role;
REVOKE UPDATE, DELETE ON stock_movements FROM app_role;
REVOKE UPDATE, DELETE ON cash_movements  FROM app_role;
-- koreksi = baris baru (compensating), bukan UPDATE
```

---

## Operasi Transaksional (pseudo-SQL)

### Checkout dengan cash lebih & kembalian (kasus Anda)

```sql
BEGIN;

-- Guard 1: idempotency
INSERT INTO sales (outlet_id, terminal_id, shift_id, cashier_id,
                   invoice_no, client_request_id, request_hash, status)
VALUES ($o, $t, $sh, $c, '', $cid, $hash, 'draft')
ON CONFLICT (client_request_id) DO NOTHING
RETURNING id INTO $sid;

IF $sid IS NULL THEN
  SELECT id INTO $sid FROM sales WHERE client_request_id = $cid;
  -- kembalikan respons lama
  COMMIT; RETURN existing_response;
END IF;

-- Guard 6: nomor invoice (transaksional, hanya jika baris baru)
UPDATE invoice_sequences
SET last_number = last_number + 1
WHERE outlet_id=$o AND terminal_id=$t AND period=$p
RETURNING last_number INTO $seq;

UPDATE sales
SET invoice_no = format('OUT%s-%s-%s-%05s', $o, $t, $p, $seq),
    status = 'pending_payment', version = version + 1
WHERE id = $sid;

-- Guard 4: reserve stok, urut product_id ASC agar tidak deadlock
FOR item IN (SELECT * FROM sale_items_to_insert ORDER BY product_id ASC) LOOP
  UPDATE inventory
  SET qty_reserved = qty_reserved + item.qty, version = version + 1
  WHERE outlet_id=$o AND product_id=item.product_id
    AND qty_on_hand - qty_reserved >= item.qty;

  IF affected_rows = 0 THEN
    ROLLBACK; RETURN 'STOCK_INSUFFICIENT:' || item.product_id;
  END IF;

  INSERT INTO stock_movements(outlet_id, product_id, type, qty_delta,
                              ref_type, ref_id, shift_id, client_request_id)
  VALUES ($o, item.product_id, 'reserve', 0, 'sale', $sid, $sh,
          uuid_generate_v5($cid, 'reserve:'||item.product_id));
END LOOP;

-- simpan sale_items
INSERT INTO sale_items(...) SELECT ...;

-- hitung total, payable
UPDATE sales SET subtotal=$sub, discount_total=$disc, tax_total=$tax,
                 total=$total, rounding=$rnd, payable=$payable, version=version+1
WHERE id=$sid;

-- Guard 2 + Guard 5: pembayaran tunai lebih
--   tendered = 100000, applied = 80000, change = 20000
INSERT INTO payments(sale_id, shift_id, method, direction, status,
                     tendered_amount, applied_amount, change_amount,
                     client_request_id)
VALUES ($sid, $sh, 'cash', 'capture', 'captured', 100000, 80000, 20000, $cid_pay);

INSERT INTO cash_movements(shift_id, type, amount, ref_type, ref_id, client_request_id)
VALUES ($sh, 'sale_cash',  +80000, 'payment', $pid, $cid_cash_sale);

INSERT INTO cash_movements(shift_id, type, amount, ref_type, ref_id, client_request_id)
VALUES ($sh, 'change_out', -20000, 'payment', $pid, $cid_cash_change);

-- Cek lunas
SELECT COALESCE(SUM(applied_amount),0) INTO $applied
FROM payments
WHERE sale_id=$sid AND direction='capture' AND status='captured';

IF $applied = $payable THEN
  -- Guard 4: commit stok
  FOR item IN (SELECT * FROM sale_items WHERE sale_id=$sid ORDER BY product_id ASC) LOOP
    UPDATE inventory
    SET qty_on_hand = qty_on_hand - item.qty,
        qty_reserved = qty_reserved - item.qty,
        version = version + 1
    WHERE outlet_id=$o AND product_id=item.product_id;

    INSERT INTO stock_movements(outlet_id, product_id, type, qty_delta,
                                ref_type, ref_id, shift_id, client_request_id)
    VALUES ($o, item.product_id, 'sale', -item.qty, 'sale', $sid, $sh,
            uuid_generate_v5($cid, 'commit:'||item.product_id));
  END LOOP;

  -- Guard 3: transisi state
  UPDATE sales SET status='paid', version=version+1
  WHERE id=$sid AND status='pending_payment';
  INSERT INTO sale_state_log(sale_id, from_status, to_status, actor_id)
  VALUES ($sid, 'pending_payment', 'paid', $c);
END IF;

-- Guard 7: audit
INSERT INTO audit_log(actor_id, action, entity_type, entity_id,
                      before, after, terminal_id, shift_id, sale_id, request_id)
VALUES ($c, 'sale.checkout', 'sale', $sid,
        jsonb_build_object('status','pending_payment'),
        jsonb_build_object('status','paid'),
        $t, $sh, $sid, $cid);

COMMIT;
```

### Refund dengan approval (Guard 2, 3, 4, 5, 7)

```sql
BEGIN;

SELECT * FROM sales WHERE id=$sid FOR UPDATE;

-- Guard 3
IF sale.status NOT IN ('paid','completed','partially_refunded') THEN ROLLBACK;

-- Guard 2: net applied
SELECT COALESCE(SUM(CASE WHEN direction='capture' AND status='captured' THEN applied_amount
                         WHEN direction='refund'  AND status='captured' THEN -applied_amount
                    END),0)
INTO $net
FROM payments WHERE sale_id=$sid;

IF $refund_amount > $net THEN ROLLBACK;     -- refund dari applied, bukan tendered

-- Guard 7: approver valid, bukan self, bukan kasir sale
IF $approver = sale.cashier_id OR $approver = $requested_by THEN ROLLBACK;
IF (SELECT role FROM staff WHERE id=$approver) NOT IN ('supervisor','manager') THEN ROLLBACK;

INSERT INTO approvals(sale_id, action, requested_by, approved_by, status, reason)
VALUES ($sid, 'refund', $requested_by, $approver, 'approved', $reason);

-- Guard 2: baris refund (applied saja, tanpa tendered/change)
INSERT INTO payments(sale_id, shift_id, method, direction, status,
                     applied_amount, change_amount, client_request_id)
VALUES ($sid, $sh, $method, 'refund', 'captured', $refund_amount, 0, $cid);

-- Guard 5: kalau refund cash, keluarkan dari drawer
IF $method = 'cash' THEN
  INSERT INTO cash_movements(shift_id, type, amount, ref_type, ref_id, client_request_id)
  VALUES ($sh, 'refund_cash', -$refund_amount, 'payment', $pid, $cid_cash);
END IF;

-- Guard 4: kembalikan stok (per item yang direfund)
FOR item IN (SELECT * FROM refund_items ORDER BY product_id ASC) LOOP
  UPDATE inventory SET qty_on_hand = qty_on_hand + item.qty, version = version + 1
  WHERE outlet_id=$o AND product_id=item.product_id;

  INSERT INTO stock_movements(outlet_id, product_id, type, qty_delta,
                              ref_type, ref_id, shift_id, client_request_id)
  VALUES ($o, item.product_id, 'refund', +item.qty, 'sale', $sid, $sh,
          uuid_generate_v5($cid, 'refund:'||item.product_id));
END LOOP;

-- Guard 3: update status
IF $net - $refund_amount = 0 THEN
  UPDATE sales SET status='refunded', version=version+1 WHERE id=$sid;
  INSERT INTO sale_state_log VALUES (..., 'refunded', $approver);
ELSE
  UPDATE sales SET status='partially_refunded', version=version+1 WHERE id=$sid;
  INSERT INTO sale_state_log VALUES (..., 'partially_refunded', $approver);
END IF;

-- Guard 7: audit
INSERT INTO audit_log(...);

COMMIT;
```

### Void (pra-settle) — pola sama dengan refund, `stock_movements.type='void'`, `payments.direction='refund'`.

### Tutup Shift (Guard 5)

```sql
BEGIN;
SELECT * FROM shifts WHERE id=$sh FOR UPDATE;
IF status <> 'open' THEN ROLLBACK;

-- Guard 5: blok jika ada yang menggantung
IF EXISTS (SELECT 1 FROM sales
           WHERE shift_id=$sh AND status IN ('draft','pending_payment','void_pending')) THEN
  ROLLBACK; RETURN 'PENDING_TRANSACTIONS';
END IF;
IF EXISTS (SELECT 1 FROM payments
           WHERE shift_id=$sh AND status='pending') THEN
  ROLLBACK; RETURN 'PENDING_PAYMENTS';
END IF;

-- Guard 5: expected dari ledger, bukan manual
SELECT sh.opening_float + COALESCE(SUM(cm.amount),0)
INTO $expected
FROM shifts sh LEFT JOIN cash_movements cm ON cm.shift_id = sh.id
WHERE sh.id = $sh
GROUP BY sh.opening_float;

-- variance != 0 → butuh supervisor (Guard 7)
IF $counted <> $expected AND $approver IS NULL THEN
  ROLLBACK; RETURN 'VARIANCE_NEEDS_APPROVAL';
END IF;

UPDATE shifts
SET status='closed', expected_cash=$expected, counted_cash=$counted,
    variance=$counted-$expected, closed_at=now(), closed_by=$staff
WHERE id=$sh;

INSERT INTO audit_log(actor_id, approver_id, action, entity_type, entity_id,
                      before, after, shift_id, request_id)
VALUES ($staff, $approver, 'shift.close', 'shift', $sh,
        jsonb_build_object('status','open'),
        jsonb_build_object('expected_cash',$expected,
                           'counted_cash',$counted,
                           'variance',$counted-$expected),
        $sh, $cid);

COMMIT;
```

---

## Rekonsiliasi (jalankan harian / per shift)

**Stok:**
```sql
SELECT i.outlet_id, i.product_id, i.qty_on_hand,
       COALESCE(SUM(sm.qty_delta),0) AS ledger_qty
FROM inventory i
LEFT JOIN stock_movements sm
  ON sm.outlet_id = i.outlet_id
 AND sm.product_id = i.product_id
 AND sm.type IN ('sale','void','refund','adjustment','opname','receive')
GROUP BY i.outlet_id, i.product_id, i.qty_on_hand
HAVING i.qty_on_hand <> COALESCE(SUM(sm.qty_delta),0);
```

**Payment vs status sale:**
```sql
SELECT s.id, s.status, s.payable,
       COALESCE(SUM(CASE
         WHEN p.direction='capture' AND p.status='captured' THEN p.applied_amount
         WHEN p.direction='refund'  AND p.status='captured' THEN -p.applied_amount
       END),0) AS net_applied
FROM sales s LEFT JOIN payments p ON p.sale_id = s.id
WHERE s.status IN ('paid','completed','partially_refunded','refunded')
GROUP BY s.id, s.status, s.payable
HAVING
  (s.status IN ('paid','completed') AND net_applied <> s.payable)
  OR (s.status = 'partially_refunded' AND (net_applied <= 0 OR net_applied >= s.payable))
  OR (s.status = 'refunded' AND net_applied <> 0);
```

**Kas per shift:**
```sql
SELECT sh.id, sh.opening_float,
       sh.opening_float + COALESCE(SUM(cm.amount),0) AS expected_from_ledger,
       COALESCE(SUM(CASE WHEN p.method='cash' AND p.direction='capture' AND p.status='captured'
                         THEN p.applied_amount END),0)
     - COALESCE(SUM(CASE WHEN p.method='cash' AND p.direction='refund'  AND p.status='captured'
                         THEN p.applied_amount END),0)
     + COALESCE(SUM(CASE WHEN cm.type IN ('cash_in','cash_out') THEN cm.amount END),0)
                                                    AS expected_from_payments
FROM shifts sh
LEFT JOIN cash_movements cm ON cm.shift_id = sh.id
LEFT JOIN payments p        ON p.shift_id  = sh.id
GROUP BY sh.id, sh.opening_float;
```

**Change = tendered − applied (setiap baris cash capture):**
```sql
SELECT id FROM payments
WHERE method='cash' AND direction='capture'
  AND change_amount <> tendered_amount - applied_amount;   -- harus 0 baris
```

---

## Ringkasan Invariant (yang harus selalu benar)

| Guard | Invariant |
|---|---|
| 1 | `(client_request_id)` unik di `sales`, `payments`, `cash_movements`, `stock_movements` |
| 2 | `sum(capture.applied) − sum(refund.applied) = payable` saat `paid`; `tendered ≥ applied`; `change = tendered − applied` (hanya cash) |
| 2 | Refund dihitung dari `applied`, bukan `tendered` |
| 3 | Transisi status hanya lewat matriks yang diizinkan; setiap transisi tercatat di `sale_state_log` |
| 4 | `qty_on_hand ≥ 0`, `qty_on_hand − qty_reserved ≥ 0`, `qty_on_hand = sum(stock_movements of on-hand types)` |
| 5 | Satu shift `open` per terminal; `expected_cash = opening_float + sum(cash_movements)`; tutup shift ditolak jika ada pending |
| 6 | `(outlet_id, invoice_no)` unik; nomor dialokasikan di transaksi yang sama dan hanya untuk request non-duplikat |
| 7 | `approved_by ≠ requested_by`; aksi sensitif menulis `approvals` + `audit_log`; `audit_log`, `stock_movements`, `cash_movements` immutable |

---

## Checklist Test Paralel Sebelum Go-Live

- [ ] 5x request checkout dengan `client_request_id` sama → **1 sale, 1 invoice, 1 payment, 1 change_out, stok potong 1x**
- [ ] Bill 80.000, tendered 100.000 → `applied=80.000, change=20.000`; expected cash shift +80.000, bukan +100.000
- [ ] Bill 80.000, tendered 50.000 → ditolak
- [ ] QRIS tendered 100.000 untuk bill 80.000 → ditolak (non-cash)
- [ ] 50 request paralel untuk stok 1 → 1 sukses, 49 gagal bersih, stok akhir 0
- [ ] Callback gateway dobel → 1 baris payment
- [ ] Refund 30.000 setelah cash 100.000 → sah; refund 90.000 → ditolak (melebihi applied)
- [ ] Self-approve refund → ditolak; approver = kasir sale → ditolak
- [ ] Dua request buka shift paralel di terminal sama → 1 sukses
- [ ] Tutup shift dengan 1 sale `pending_payment` → ditolak
- [ ] Tutup shift dengan variance ≠ 0 tanpa approver → ditolak
- [ ] Retry checkout setelah timeout → invoice yang sama, tidak ada nomor baru
- [ ] Rekonsiliasi harian: kas, stok, payment gateway semua seimbang



-- ERRROR

# Error Handling di POS Multi-Kasir — Melengkapi 7 Guard

Error handling bukan guard terpisah, tapi **kontrak** yang menyatukan semua guard. Salah menangani error = guard Anda jebol dari samping. Contoh paling nyata: kasir klik "Bayar", server crash, UI tampil "Gagal", kasir klik ulang → tanpa idempotency, transaksi dobel. Padahal Guard 1 seharusnya menyelamatkan itu.

---

## 1. Klasifikasi Error — Ini Fondasinya

Kesalahan paling umum: memperlakukan **semua error non-200 sebagai "gagal"**. Di POS, error dibagi 3 kelas dengan konsekuensi berbeda:

| Kelas | Contoh | Efek samping | Aksi client |
|---|---|---|---|
| **Definitive failure** | 400, 401, 403, 404, 422 | Dijamin **tidak ada** commit | Jangan retry, tampilkan error |
| **Definitive success** | 200, 201 | Sudah commit | Lanjut |
| **Ambiguous** | 500, 502, 503, 504, timeout, koneksi putus | **Tidak tahu** commit atau tidak | Retry **dengan idempotency key**, atau cek status dulu |

**Aturan emas:**
> Hanya `4xx` yang boleh diperlakukan sebagai "tidak terjadi apa-apa". Semua `5xx` dan timeout adalah **"status belum pasti"**, bukan "gagal".

Kalau UI menampilkan "Transaksi gagal" untuk 500/timeout, Anda mendorong kasir melakukan double transaction — dan Guard 1 jadi satu-satunya penyelamat. Jangan andalkan itu saja; UI harus jujur.

---

## 2. Error Contract (Envelope)

Setiap response error — dari endpoint mana pun — memakai format yang sama:

```json
{
  "error": {
    "code": "STOCK_INSUFFICIENT",
    "message": "Stok tidak cukup untuk produk SKU-123",
    "details": {
      "product_id": 123,
      "requested": 5,
      "available": 2
    },
    "request_id": "018f3c9e-...",
    "retryable": false,
    "retry_after_ms": null
  }
}
```

Field wajib:
- **`code`** — machine-readable, stabil, untuk client logic. Jangan parsing `message`.
- **`message`** — human-readable, untuk ditampilkan ke kasir.
- **`request_id`** — UUID untuk korelasi log & support. Client harus simpan ini kalau kasir komplain.
- **`retryable`** — true/false eksplisit. Client tidak perlu menghafal mapping status.
- **`details`** — konteks tambahan (product_id, shift_id, dsb.) supaya UI bisa tampilkan aksi yang tepat.

**Jangan pernah** expose stack trace, SQL, atau nama internal ke client. Log detail di server dengan `request_id` yang sama.

---

## 3. HTTP Status Mapping untuk Kasus POS

| Status | Kapan dipakai | Contoh `code` | Retry? |
|---|---|---|---|
| **400** | Payload invalid, format salah | `VALIDATION_ERROR`, `INVALID_AMOUNT` | ❌ |
| **401** | Belum login / token expired | `UNAUTHENTICATED` | ❌ (login ulang) |
| **403** | Login tapi tidak berwenang | `SELF_APPROVAL_FORBIDDEN`, `ROLE_NOT_ALLOWED` | ❌ |
| **404** | Resource tidak ada | `SALE_NOT_FOUND`, `PRODUCT_NOT_FOUND` | ❌ |
| **409** | Konflik state/idempotency/versi | `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`, `INVALID_STATE_TRANSITION`, `VERSION_CONFLICT` | ⚠️ Cek status dulu |
| **422** | Valid tapi melanggar aturan bisnis | `STOCK_INSUFFICIENT`, `SHIFT_NOT_OPEN`, `REFUND_EXCEEDS_CAPTURED` | ❌ |
| **429** | Rate limit | `RATE_LIMITED` | ✅ setelah `retry_after_ms` |
| **500** | Bug server / crash | `INTERNAL_ERROR` | ✅ dengan idempotency key |
| **502/503/504** | Upstream mati / timeout | `PAYMENT_GATEWAY_TIMEOUT`, `SERVICE_UNAVAILABLE` | ✅ dengan idempotency key |
| **Timeout client** | Tidak ada response | — | ✅ dengan idempotency key + cek status |

**Kenapa 409 dipisah dari 422:**
- 409 = state bisa berubah kalau client memperbaiki (reload, koreksi key) → **client harus cek state dulu**.
- 422 = aturan bisnis, tidak akan berubah dengan retry → **jangan retry**, tampilkan error.

**Kenapa 422 dipisah dari 400:**
- 400 = syntax salah, programmer salah.
- 422 = request valid, tapi tidak bisa diproses (stok habis, shift tutup, refund melebihi). Ini bukan bug — ini aturan bisnis. UI perlu bedakan: 400 → "ada bug, lapor", 422 → "stok habis, silakan ganti".

---

## 4. Retry Policy per Status

```text
400, 401, 403, 404, 422  → STOP, tampilkan error. Jangan retry.
409                      → STOP. GET status sale dulu, baru putuskan.
429                      → WAIT retry_after_ms, lalu retry (maks 3x).
500, 502, 503, 504       → RETRY dengan client_request_id yang SAMA, backoff eksponensial.
Timeout / koneksi putus  → AMBIGU. Cek status via GET, retry jika belum ada.
```

**Aturan keras:**
> Setiap retry WAJIB memakai `client_request_id` yang sama. Kalau tidak, Anda menciptakan transaksi baru — dan semua guard 1–7 runtuh sekaligus.

Retry backoff yang wajar: 300ms → 800ms → 2s → 5s, maks 4 percobaan. Setelah itu, tampilkan "Status belum pasti — hubungi supervisor dengan ID: {request_id}".

---

## 5. Skenario 500 di Tengah Transaksi

Ini kasus yang paling ditakuti. Bedakan dua situasi:

### 5a. 500 pada transaksi single-DB (checkout tunai)

Semua side effect (INSERT sale, items, payments, cash_movements, stock_movements, UPDATE inventory, UPDATE invoice_sequence) berada dalam **satu transaksi DB**. Maka:

- **Crash sebelum COMMIT** → semua rollback. Client retry dengan key sama → proses baru dari nol. Aman.
- **Crash setelah COMMIT, sebelum kirim response** → semua data sudah ada. Client retry dengan key sama → Guard 1 mendeteksi, kembalikan hasil lama. Aman.
- **Crash di tengah** (misal setelah INSERT sale, sebelum INSERT payment) → transaksi rollback, tidak ada data parsial. Aman.

**Ini sebabnya semua side effect WAJIB dalam satu transaksi DB.** Kalau Anda memecah: `INSERT sale` commit, lalu panggil printer, lalu `INSERT payment` commit — crash di tengah meninggalkan data parsial. Client retry, Guard 1 lihat sale ada di status `draft`, dan harus **melanjutkan** atau **memulai ulang** — logika recovery yang rumit. Hindari.

### 5b. 500 pada transaksi dengan external call (checkout QRIS/kartu)

Di sini ada **saga**, bukan transaksi tunggal. Urutan yang benar:

```
1. BEGIN
2. INSERT sales (status='pending_payment') + sale_items
3. INSERT payments (status='pending', method='qris')
4. INSERT outbox_event('payment.initiate', payment_id)
5. COMMIT
--- di luar transaksi ---
6. Worker ambil outbox → call gateway dengan idempotency key gateway
7. Callback gateway (atau polling) → BEGIN → UPDATE payments 'captured' atau 'failed'
   → jika captured dan sale lunas, UPDATE sales 'paid' → COMMIT
```

**500 pada step 1–5** → rollback penuh, retry aman.
**500 pada step 6** → outbox tetap ada, worker retry. Client sudah dapat response dari step 5 (201 Created), jadi tidak melihat 500.
**500 pada step 7** → data di DB `pending`, gateway sudah `captured`. **Callback berikutnya** (gateway retry) atau **job polling** akan menutup gap. Client yang cek status melihat `pending`, bukan 500.

**Kesimpulan:** client tidak boleh dapat 500 untuk external call. Server harus **merespons 201/202 segera** setelah DB commit, dan proses eksternal berjalan asynchronous lewat outbox + callback. Kalau client memang harus tahu hasil akhir, gunakan pola **polling** (`GET /sales/{id}/status`).

---

## 6. Skenario Timeout — Paling Berbahaya

Timeout bukan error — tidak ada response sama sekali. Client **tidak tahu** apakah server menerima request.

**Alur yang benar di client (kasir UI):**

```
1. Kasir klik "Bayar"
2. Kirim POST /sales dengan client_request_id=UUID-K
3. Timeout 10 detik
4. UI tampilkan: "Sedang memproses..."
5. Client TIDAK boleh tampilkan "Gagal". Jangan aktifkan tombol bayar lagi dengan key baru.
6. Client GET /sales/by-client-request/{UUID-K}
   ├─ 200 → ada → tampilkan struk (sukses)
   ├─ 404 → tidak ada → aman untuk retry POST dengan UUID-K
   └─ timeout lagi → tunggu, ulangi cek dengan backoff
7. Setelah 60 detik belum pasti → tampilkan "Status belum pasti. Hubungi supervisor.
   ID: {request_id}"
```

**Aturan UI:**
> Tombol "Bayar" hanya boleh ter-disable atau berubah status saat request in-flight. Kalau timeout, tombol TIDAK boleh aktif kembali sebelum status dicek. Kalau aktif lagi dengan key baru, double transaction.

**Endpoint pendukung yang WAJIB ada:**

```
GET /sales/by-client-request/{client_request_id}
→ 200 { sale, payments, status, invoice_no }
→ 404 { code: "NOT_FOUND" }
```

Ini penting bukan hanya untuk retry, tapi untuk audit dan support ("Transaksi saya hilang, ID-nya ini").

---

## 7. Error dari Payment Gateway

Non-cash punya mode kegagalan tambahan:

| Kasus gateway | Handling server | Tampilan kasir |
|---|---|---|
| **Timeout saat call** | Payment tetap `pending`. Worker retry dengan gateway idempotency key | "Menunggu pembayaran..." |
| **Response sukses, callback belum datang** | Polling job setiap 30 detik, maks 5 menit | "Menunggu konfirmasi..." |
| **Callback dobel** | Unique `gateway_txn_id` (Guard 2) → `ON CONFLICT DO NOTHING` | Sudah sukses, tidak berubah |
| **Callback out-of-order** | Handler idempotent, tidak asumsi urutan | — |
| **Callback untuk sale yang sudah `voided`** | Tolak, tapi **jangan refund otomatis**. Flag untuk rekonsiliasi manual | Alert ke supervisor |
| **Gateway bilang `failed`, callback bilang `captured`** | **Percaya callback**, update `captured`. Log konflik | Sukses |
| **Gateway bilang `captured`, tapi stok sudah habis (rare)** | Kompensasi: refund otomatis, `sale.status='voided'`, audit log | "Transaksi dibatalkan, dana dikembalikan" |

**Aturan:** setiap panggilan ke gateway **wajib** punya `idempotency_key` dari sisi server (bukan dari client), disimpan di tabel payment (`gateway_request_id`). Retry tidak boleh men-charge dua kali.

---

## 8. Mapping Error ↔ Guard

Setiap guard punya error code spesifik yang harus bisa dikenali client:

| Guard | Error `code` | HTTP | Retry? |
|---|---|---|---|
| **1** | `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD` | 409 | ❌ |
| **1** | `DUPLICATE_REQUEST` | 200 (return existing) | — |
| **2** | `PAYMENT_EXCEEDS_PAYABLE` | 422 | ❌ |
| **2** | `NON_CASH_TENDERED_MISMATCH` | 422 | ❌ |
| **2** | `REFUND_EXCEEDS_CAPTURED` | 422 | ❌ |
| **3** | `INVALID_STATE_TRANSITION` | 409 | ❌ (reload) |
| **3** | `SALE_ALREADY_PAID` | 409 | ❌ |
| **3** | `VERSION_CONFLICT` | 409 | ❌ (reload) |
| **4** | `STOCK_INSUFFICIENT` | 422 | ❌ |
| **4** | `STOCK_RESERVATION_EXPIRED` | 422 | ❌ |
| **5** | `SHIFT_NOT_OPEN` | 422 | ❌ |
| **5** | `SHIFT_HAS_PENDING_TRANSACTIONS` | 422 | ❌ |
| **5** | `TERMINAL_SHIFT_TAKEN` | 409 | ❌ (pilih terminal lain) |
| **5** | `VARIANCE_NEEDS_APPROVAL` | 422 | ❌ |
| **6** | `INVOICE_SEQUENCE_CONFLICT` | 500 internal | ✅ (retry internal, bukan client) |
| **7** | `SELF_APPROVAL_FORBIDDEN` | 403 | ❌ |
| **7** | `APPROVAL_REQUIRED` | 403 | ❌ (minta supervisor) |
| **7** | `APPROVAL_REJECTED` | 403 | ❌ |

**Catatan Guard 6:** `INVOICE_SEQUENCE_CONFLICT` seharusnya **tidak pernah** sampai ke client. Server retry internal (ambil nomor baru, karena transaksi rollback). Kalau sampai 500 ke client, itu bug — sequence update dan insert sale harus satu transaksi.

---

## 9. Validation Error — Format yang Berguna untuk UI

Untuk 400/422 dengan banyak field, kembalikan daftar:

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Beberapa field tidak valid",
    "details": {
      "fields": [
        { "path": "items[0].qty", "code": "MUST_BE_POSITIVE" },
        { "path": "payments[0].tendered_amount", "code": "LESS_THAN_APPLIED" }
      ]
    },
    "request_id": "018f3c9e-...",
    "retryable": false
  }
}
```

Kasir UI bisa highlight field yang salah, bukan sekadar "ada yang salah".

---

## 10. Logging & Observability untuk Error

Setiap error harus bisa dilacak. Log terstruktur wajib:

```json
{
  "level": "error",
  "ts": "2026-10-18T19:01:12.345Z",
  "request_id": "018f3c9e-...",
  "trace_id": "abc123",
  "http_status": 500,
  "code": "INTERNAL_ERROR",
  "endpoint": "POST /sales",
  "sale_id": null,
  "client_request_id": "5f2a...",
  "terminal_id": 2,
  "shift_id": 87,
  "staff_id": 12,
  "latency_ms": 340,
  "error": "sql: transaction commit timeout",
  "stack": "..."   // hanya di server log, JANGAN ke client
}
```

Metrics yang wajib ada per endpoint:
- `http_requests_total{status, code, endpoint}` — untuk error rate
- `http_request_duration_seconds` — untuk deteksi latency spike
- `idempotency_hits_total` — berapa kali request duplikat masuk
- `state_transition_rejected_total{from,to}` — deteksi bug UI
- `payment_gateway_errors_total{code}` — kesehatan eksternal

Alert untuk:
- Spike `5xx` > 1% selama 5 menit
- `idempotency_hits_total` tiba-tiba naik (indikasi client retry loop)
- `payment_gateway_timeout_total` > threshold
- `stock_movements` rekonsiliasi gagal

---

## 11. 404 — Aturan Khusus

404 di POS ada beberapa rasa:

| Kasus | Aturan |
|---|---|
| `GET /sales/{id}` untuk sale yang tidak ada | 404 wajar. UI: "Transaksi tidak ditemukan". |
| `GET /sales/by-client-request/{key}` tidak ada | 404 **normal** — berarti request belum/tidak pernah sampai. Client **boleh retry POST**. |
| `POST /sales` merujuk `product_id` yang tidak ada | 422 `PRODUCT_NOT_FOUND` (bukan 404, karena resource-nya adalah `sales`, bukan produk). |
| `POST /refund` merujuk `sale_id` yang tidak ada | 404 `SALE_NOT_FOUND`. |
| Akses sale milik shift lain oleh kasir | **403 atau 404** (jangan 403 yang membocorkan eksistensi). |

**Aturan data:** jangan pernah **hard delete** `sales`, `payments`, `cash_movements`, `stock_movements`, `audit_log`. Kalau perlu "sembunyikan", pakai `is_active` di master data. Untuk ledger, `voided`/`cancelled` adalah state, bukan penghapusan. 404 untuk ledger = bug.

---

## 12. UI Kasir — Panduan Tampilan

| Kondisi | Tampilan | Tombol Bayar | Aksi Otomatis |
|---|---|---|---|
| In-flight | "Memproses..." | Disable | — |
| 200/201 | Struk tercetak | Disable (selesai) | — |
| 400/422 | "Stok tidak cukup untuk SKU-123" | Enable (koreksi) | — |
| 403 | "Butuh persetujuan supervisor" | Disable | Buka modal PIN |
| 404 (retry POST) | "Mencoba ulang..." | Disable | Retry POST dengan key sama |
| 409 | "Transaksi sudah berubah, memuat ulang..." | Disable | GET status → tampilkan status sebenarnya |
| 500 / timeout | **"Status belum pasti. Mengecek..."** | Disable | GET status dengan backoff |
| 60 detik belum pasti | "Hubungi supervisor. ID: {request_id}" | Disable | Stop, tampilkan ID |

**Jangan pernah** tampilkan "Gagal" untuk 500/timeout. Itu memicu double transaction.

---

## 13. Test Cases Error Handling (Tambahan dari Checklist Sebelumnya)

- [ ] POST `/sales` balas 500 setelah commit → retry dengan key sama → 200 + sale yang sama, tidak ada duplikat
- [ ] POST `/sales` balas 500 sebelum commit → retry → 1 sale baru, tidak ada parsial
- [ ] POST `/sales` timeout → GET by-client-request → 404 → retry POST → sukses 1 sale
- [ ] POST `/sales` timeout → GET by-client-request → 200 → UI tampilkan sukses, **tidak** retry POST
- [ ] POST `/sales` dengan key sama tapi payload beda → 409 `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`
- [ ] Callback gateway dobel setelah 500 di handler → tetap 1 baris payment
- [ ] Gateway timeout saat call → payment tetap `pending`, worker retry, akhirnya captured
- [ ] UI tidak mengaktifkan tombol bayar saat request in-flight
- [ ] Response error selalu punya `code`, `request_id`, `retryable`
- [ ] Log server punya `request_id` yang sama dengan response client
- [ ] Semua 4xx tidak menyebabkan perubahan state di DB
- [ ] Spike 5xx men-trigger alert

---

## Ringkas

- **Error bukan "berhasil/gagal".** Tiga kelas: definitive failure, definitive success, ambiguous.
- **`5xx` dan timeout = "belum pasti", bukan "gagal".** UI harus cek status sebelum retry.
- **Setiap retry wajib pakai `client_request_id` yang sama.** Tanpa itu, Guard 1 tidak melindungi apa pun.
- **Semua side effect dalam satu transaksi DB.** Jangan pecah commit di tengah.
- **External call pakai outbox + callback + polling.** Client dapat 201/202, bukan 500.
- **Error contract seragam:** `code`, `message`, `details`, `request_id`, `retryable`.
- **Jangan hard delete.** 404 untuk ledger = bug.
- **Log dan metric per `code` dan `request_id`** — tanpa ini, investigasi selisih kas jadi mimpi buruk.
- **UI adalah garis pertahanan terakhir.** Kalau UI memperlakukan 500 sebagai gagal, guard terbaik pun tidak menolong.

-- SIMPULAN
# Kombinasi Error Handling + 7 Guard — Cocok Nggak?

## Verdict

**Cocok, bahkan saling melengkapi.** Tapi ada satu koreksi framing: error handling **bukan guard ke-8**. Dia adalah **protokol lintas guard** — cara semua guard berkomunikasi ke client dan cara client bereaksi. Tanpa protokol ini, guard Anda benar di server tapi jebol di UI.

Buktinya:
- **Guard 1 tanpa error handling** → client retry 500 dengan `client_request_id` baru → duplikat. Guard 1 tidak sempat menolong karena key-nya beda.
- **Error handling tanpa Guard 1** → client retry 500 dengan key sama → server insert sale baru → duplikat. Protokol retry justru memperparah.
- **Keduanya bersama** → **exactly-once business semantics**. Ini properti baru yang tidak dimiliki salah satu saja.

Jadi bukan "coock atau tidak", tapi **"cocok kalau tiga titik integrasi ini dibuat eksplisit"**.

---

## 1. Titik Integrasi: Error Contract ↔ Guard

Setiap guard harus punya **error code yang stabil** (machine-readable), dan error handling harus tahu artinya. Tabel ini yang harus ada di API spec Anda:

| Guard | Aksi bisnis ditolak | HTTP + code | Client harus apa |
|---|---|---|---|
| **1** Idempotency | Key sama, payload beda | `409 IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD` | Stop, audit. Ini bug client. |
| **1** Idempotency | Key sama, request duplikat | `200` + body lama | Tampilkan hasil lama. **Jangan** retry. |
| **2** Payment | `tendered < applied` (cash) | `422 CASH_TENDERED_LESS_THAN_APPLIED` | Koreksi input |
| **2** Payment | Non-cash `tendered ≠ applied` | `422 NON_CASH_TENDERED_MISMATCH` | Koreksi input |
| **2** Payment | Refund > captured | `422 REFUND_EXCEEDS_CAPTURED` | Koreksi nominal |
| **2** Payment | Gateway timeout | `202 PENDING` | Poll status |
| **3** State | Transisi ilegal | `409 INVALID_STATE_TRANSITION` | GET sale → tampilkan state sebenarnya |
| **3** State | `version` mismatch | `409 VERSION_CONFLICT` | Reload sale, ulangi dengan version baru |
| **3** State | Bayar sale `paid` | `409 SALE_ALREADY_PAID` | Tampilkan struk lama |
| **4** Stock | Stok kurang | `422 STOCK_INSUFFICIENT` | Tampilkan qty tersedia |
| **4** Stock | Reservasi expired | `422 STOCK_RESERVATION_EXPIRED` | Re-add to cart |
| **5** Shift | Belum buka shift | `422 SHIFT_NOT_OPEN` | Redirect buka shift |
| **5** Shift | Terminal sudah punya shift open | `409 TERMINAL_SHIFT_TAKEN` | Pilih terminal lain |
| **5** Shift | Tutup shift ada pending | `422 SHIFT_HAS_PENDING_TRANSACTIONS` | Settle/void dulu |
| **5** Shift | Variansi butuh approval | `422 VARIANCE_NEEDS_APPROVAL` | Minta supervisor |
| **6** Invoice | Sequence conflict | **500 internal** → **wajib retry internal** | **Tidak boleh sampai ke client** |
| **7** Audit | Self-approval | `403 SELF_APPROVAL_FORBIDDEN` | Minta supervisor lain |
| **7** Audit | Butuh approval | `403 APPROVAL_REQUIRED` | Buka modal PIN |

**Aturan:** setiap error code di tabel ini harus punya test case di checklist go-live. Kalau UI tidak tahu arti `code`, dia akan memperlakukan sebagai "gagal umum" → double transaction.

---

## 2. Penyesuaian Skema (Kecil, tapi Kritis)

Skema sebelumnya sudah 90% benar. Tambah/koreksi ini:

### 2a. `idempotency_keys` — perjelas lifecycle

```sql
CREATE TABLE idempotency_keys (
  key             UUID PRIMARY KEY,
  endpoint        TEXT NOT NULL,
  request_hash    TEXT NOT NULL,
  response_status INT,          -- NULL = in-flight, 2xx = sukses, TIDAK diisi untuk 4xx
  response_body   JSONB,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Index untuk rekonsiliasi
CREATE INDEX ix_idempotency_created ON idempotency_keys(created_at);
```

**Aturan lifecycle yang WAJIB:**
- Row idempotency **hidup di transaksi DB yang sama** dengan business logic.
- **2xx** → commit row dengan `response_status` terisi. Retry mengembalikan cache.
- **4xx/422** → business logic rollback → **row ikut hilang**. Retry **re-evaluasi fresh**. Ini penting: stok habis hari ini, retry besok bisa sukses.
- **5xx (crash setelah commit)** → row ada dengan status 2xx → retry mengembalikan cache.
- **5xx (crash sebelum commit)** → row ikut rollback → retry proses fresh.
- **Concurrent request dengan key sama** → yang kedua **block** di row lock, lalu lihat status: cache atau re-evaluasi. **Jangan** return "in-flight" ke client — bikin UI bingung; cukup tunggu.
- **TTL** — hapus row `response_status IS NULL` yang lebih dari 5 menit (indikasi crash). Job housekeeping.

### 2b. `request_id` vs `client_request_id` — dua kolom berbeda

Ini yang paling sering dikacaukan. Definisikan eksplisit:

| Kolom | Arti | Siapa yang generate | Berubah saat retry? |
|---|---|---|---|
| `client_request_id` | **Satu niat bisnis** ("saya mau buat sale ini") | Client, sekali | ❌ tidak |
| `request_id` | **Satu HTTP request fisik** | Server (dari header `X-Request-Id` atau generate) | ✅ ya, tiap retry beda |

Kegunaan:
- `client_request_id` → Guard 1 (idempotency), korelasi dengan bisnis intent
- `request_id` → log correlation, support ticket ("kasir komplain, ini request mana")

Tambahan skema (opsional tapi sangat membantu support):

```sql
ALTER TABLE sales    ADD COLUMN request_id UUID;
ALTER TABLE payments ADD COLUMN request_id UUID;
-- audit_log sudah punya request_id, tambahkan juga client_request_id:
ALTER TABLE audit_log ADD COLUMN client_request_id UUID;
```

### 2c. `payments.gateway_request_id` — idempotency ke gateway

```sql
ALTER TABLE payments ADD COLUMN gateway_request_id TEXT;
CREATE UNIQUE INDEX ux_payments_gateway_request
  ON payments(gateway_request_id) WHERE gateway_request_id IS NOT NULL;
```

Ini berbeda dari `gateway_txn_id` (dari gateway). `gateway_request_id` adalah **key yang kita kirim ke gateway**, supaya retry kita tidak men-charge dua kali. Wajib untuk QRIS/kartu.

### 2d. Outbox untuk external call

Kalau pakai QRIS/kartu, `payments` + `outbox_events` dalam satu transaksi. Ini yang membuat 500 di handler tidak sampai ke client (client dapat 202, worker yang urus).

```sql
CREATE TABLE outbox_events (
  id           BIGSERIAL PRIMARY KEY,
  event_type   TEXT NOT NULL,               -- 'payment.initiate', 'payment.capture'
  payload      JSONB NOT NULL,
  status       TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','processing','done','failed')),
  attempt      INT NOT NULL DEFAULT 0,
  next_try_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_outbox_pending ON outbox_events(next_try_at) WHERE status='pending';
```

---

## 3. Dua Keputusan Desain yang WAJIB Eksplisit

### 3a. Jangan cache 4xx di idempotency

Ini paling mudah salah. Kalau Anda cache `422 STOCK_INSUFFICIENT` di `idempotency_keys`, retry besok (saat stok sudah tersedia) akan tetap dapat 422. **Salah secara bisnis.**

Aturan: **rollback menghapus row idempotency**. Retry dengan key sama dan payload sama:
- Jika state berubah → hasil baru (bisa sukses).
- Jika state sama → hasil sama (422 lagi, karena Guard 4 memang tolak).

Client yang **ingin mengubah payload** (misal kurangi qty) → **wajib pakai key baru** → niat bisnis baru. Guard 1 mendeteksi "key sama + hash beda = 409" untuk mencegah client iseng reuse key dengan payload berbeda.

### 3b. UI adalah bagian dari protokol, bukan kosmetik

Kalau UI memperlakukan 500/timeout sebagai "gagal":
- Kasir klik "Bayar" lagi → key baru → Guard 1 tidak menolong → **duplikat**.
- Stok kepotong 2x, kas tercatat 2x, kasir panik, supervisor kewalahan.

UI yang benar:
```
500/timeout → "Status belum pasti, mengecek..." → GET /sales/by-client-request/{K}
  ├─ 200 → tampilkan struk
  ├── 404 → retry POST dengan K yang sama
  └─ timeout → backoff, ulangi
```

**Tombol "Bayar" tidak boleh re-enable sampai status pasti.** Ini bukan UX, ini correctness.

---

## 4. Properti Baru dari Kombinasi

Kombinasi 7 guard + error handling menghasilkan tiga properti yang tidak dimiliki salah satunya:

### 4a. Exactly-once business semantics
- **At-least-once delivery** — dari error handling (retry pada 5xx/timeout).
- **At-most-once effect** — dari Guard 1 (idempotency + unique).
- **Gabungan** = business intent dieksekusi tepat sekali, walau HTTP request dikirim berkali-kali.

### 4b. Crash safety
- Semua side effect dalam **satu transaksi DB** → rollback otomatis.
- Retry dengan key sama → **resume atau selesaikan** tanpa duplikat.
- Tidak ada state parsial di DB — crash di mana pun = rollback penuh.

### 4c. Deterministic recovery
Setiap error code punya jalur recovery yang bisa diajarkan ke kasir dan diprogram di UI:

| Situasi | Jalur recovery |
|---|---|
| `422 STOCK_INSUFFICIENT` | Kurangi qty, atau ganti produk, atau void item |
| `409 VERSION_CONFLICT` | Reload sale, ulangi |
| `409 IDEMPOTENCY_KEY_REUSED` | Bug client — escalate |
| `403 APPROVAL_REQUIRED` | Panggil supervisor |
| `500` / timeout | GET status → tentukan sukses/retry |

Tanpa error handling, recovery tidak deterministik — kasir improvisasi, dan improvisasi di POS = selisih kas.

---

## 5. Simulasi Gabungan (Skenario dengan Error Path)

Setup: 1 outlet, terminal T1, shift open, kasir K1, produk X stok 1. Pelanggan beli X, bayar tunai 100.000 untuk bill 80.000.

### Skenario 1 — Sukses normal
```
POST /sales (key=K1, cash 100.000)
  Guard 6: ambil invoice OUT1-T1-20261018-00042
  Guard 4: reserve X (stok 1→0 reserved)
  Guard 2: payments(tendered=100.000, applied=80.000, change=20.000)
  Guard 5: cash_movements(sale_cash +80.000, change_out −20.000)
  Guard 4: commit stock (on_hand 1→0)
  Guard 3: status paid
  Guard 1: idempotency_keys(K1, 201)
  Guard 7: audit_log(sale.checkout)
COMMIT → 201 dengan sale lengkap
```

### Skenario 2 — Crash setelah commit, sebelum response
```
POST /sales (key=K1) → COMMIT sukses → server crash → client 500
Client retry POST /sales (key=K1)
  Guard 1: idempotency_keys(K1) ditemukan, status=201
  → kembalikan cached response
COMMIT (no-op)
```
✅ **1 sale**, bukan 2. Inilah nilai kombinasi: Guard 1 + error handling.

### Skenario 3 — Crash sebelum commit
```
POST /sales (key=K1) → BEGIN → insert partial → crash
DB rollback otomatis. idempotency_keys(K1) juga rollback.
Client retry POST /sales (key=K1)
  Guard 1: key tidak ada → proses fresh
  → 201
```
✅ **1 sale**. Tidak ada state parsial.

### Skenario 4 — Stok habis, retry setelah restock
```
T1: POST /sales (key=K1, qty=1) → Guard 4 tolak → 422 STOCK_INSUFFICIENT
  Rollback: sales, payments, idempotency_keys(K1) semua hilang
Restock: on_hand 0 → 5
T2: retry POST /sales (key=K1, qty=1)
  Guard 1: key tidak ada (di-rollback)
  Guard 4: reserve OK
  → 201
```
✅ **Sesuai bisnis**: key yang sama, state berubah, hasil baru. Kalau idempotency cache 4xx, T2 akan dapat 422 palsu selamanya.

### Skenario 5 — Kasir klik Bayar 2x (lag)
```
Klik 1: POST /sales (key=K1) → in-flight
Klik 2: POST /sales (key=K1) → Guard 1 block di row lock
Klik 1: commit → 201
Klik 2: unblock → 201 cached (sale sama)
```
✅ **1 sale**. Bahkan klik dobel di level HTTP di-handle.

### Skenario 6 — QRIS timeout
```
POST /sales (key=K1, QRIS) 
  BEGIN: sale pending, payment pending, outbox('payment.initiate')
  COMMIT → 202 { status: pending }
Worker: call gateway → timeout
  Retry dengan gateway_request_id yang sama (tidak double charge)
Callback datang → payment captured → sale paid
```
✅ Client tidak pernah lihat 500. Kalau client polling: `GET /sales/{id}/status` → pending → paid.

### Skenario 7 — Refund dengan approval
```
Kasir request refund → 403 APPROVAL_REQUIRED (Guard 7)
Supervisor PIN → POST /refund (key=K2, approver=S)
  Guard 7: S ≠ K1, role OK
  Guard 2: refund ≤ captured (applied, bukan tendered)
  Guard 5: cash refund −30.000
  Guard 4: refund stock +1
  Guard 3: status partially_refunded
  Guard 1: idempotency_keys(K2, 201)
  Guard 7: audit_log(payment.refund)
Retry POST /refund (key=K2) karena timeout → 201 cached
```
✅ **1 refund**, bukan 2.

### Skenario 8 — 500 di tengah refund multi-item
```
Refund 2 item, item 1 stock_refund sukses, item 2 crash
DB rollback: item 1 refund juga hilang, payment refund hilang, idempotency hilang
Retry dengan key=K2 → proses fresh → 201
```
✅ Konsisten: **tidak ada refund parsial**.

---

## 6. Yang TIDAK Cocok Kalau Salah Konfigurasi

Ini yang harus dicek di review code:

| Kesalahan konfigurasi | Akibat | Perbaikan |
|---|---|---|
| Cache 4xx di `idempotency_keys` | Retry setelah stok restock tetap 422 | Rollback hapus idempotency row |
| `idempotency_keys` di transaksi terpisah dari business | Crash = idempotency row tanpa business row, atau sebaliknya | Satu transaksi |
| UI treat 500 sebagai "gagal" | Kasir klik ulang dengan key baru → duplikat | UI retry dengan key sama + cek status |
| Retry dengan key baru di HTTP client library | Duplikat di level HTTP | Retry interceptor wajib preserve `client_request_id` |
| `INVOICE_SEQUENCE_CONFLICT` sampai ke client | Kasir lihat 500, klik ulang → gap nomor / duplikat | Retry internal di server |
| Error code berubah setelah go-live | UI logic broken, salah handling | Versioning API + error code deprecation |
| Tidak ada `GET /sales/by-client-request/{K}` | Retry ambiguity tidak bisa diputuskan | Tambah endpoint |
| `gateway_request_id` tidak disimpan | Retry ke gateway men-charge 2x | Simpan & reuse |
| Outbox tidak ada untuk QRIS | 500 di handler → client retry → charge 2x | Outbox + worker |

---

## 7. Checklist Gabungan

Error handling + 7 guard dianggap cocok kalau semua ini lulus:

### Idempotency & crash
- [ ] `idempotency_keys` di transaksi DB yang sama dengan business logic
- [ ] 4xx/422 → rollback → row idempotency hilang
- [ ] 2xx → commit → row idempotency bertahan, retry mengembalikan cache
- [ ] Concurrent duplicate → block di row lock, bukan return "in-flight"
- [ ] Job hapus `idempotency_keys` dengan status NULL > 5 menit

### Error code per guard
- [ ] Setiap guard punya error `code` stabil (tabel di section 1)
- [ ] Setiap error code punya test case di checklist go-live
- [ ] UI tahu arti setiap code — tidak memperlakukan sebagai "gagal umum"
- [ ] `request_id` (per HTTP) ≠ `client_request_id` (per niat) — dua kolom terpisah

### Recovery path
- [ ] `GET /sales/by-client-request/{key}` ada dan akurat
- [ ] 500/timeout → UI cek status, **tidak** retry dengan key baru
- [ ] Tombol bayar tidak re-enable sampai status pasti
- [ ] Setiap error code punya jalur recovery yang diajarkan ke kasir

### External call
- [ ] `payments.gateway_request_id` disimpan & di-reuse di retry
- [ ] Outbox untuk `payment.initiate` dan `payment.capture`
- [ ] Callback gateway idempotent (`gateway_txn_id` unique)
- [ ] Worker retry dengan backoff, bukan crash

### Konsistensi lintas guard
- [ ] `idempotency_keys.response_body.sale_id` == `sales.id` untuk key yang sama (rekonsiliasi)
- [ ] `payments.direction='refund'` ≤ `sum(capture.applied)` — bukan `tendered`
- [ ] `cash_movements.change_out` == `sum(payments.change_amount)` per shift
- [ ] `INVOICE_SEQUENCE_CONFLICT` tidak pernah sampai client

---

## Ringkas

- **Error handling bukan guard ke-8.** Dia **protokol** yang menyatukan 7 guard ke client.
- **Cocok**, karena keduanya saling bergantung:
  - Guard butuh error handling supaya client **tidak** melakukan retry dengan key baru.
  - Error handling butuh guard supaya retry dengan key sama **tidak** menggandakan efek.
- **Properti baru**: exactly-once business semantics, crash safety, deterministic recovery.
- **Tiga penyesuaian skema kecil**: lifecycle `idempotency_keys` (rollback hapus, cache 2xx saja), `request_id` vs `client_request_id`, `gateway_request_id` + outbox.
- **Dua keputusan eksplisit**: jangan cache 4xx; UI adalah bagian dari protokol, bukan kosmetik.
- **Satu aturan tanpa pengecualian**: setiap retry memakai `client_request_id` yang sama. Kalau tidak, Guard 1 tidak melindungi apa pun.

Kalau semua itu dibuat eksplisit, kombinasi ini bukan hanya cocok — **kombinasi inilah yang membuat 7 guard benar-benar bekerja di dunia nyata**. Tanpa error handling, guard Anda hanya kertas di server yang tidak sampai ke kasir.


--
# Tabel Lengkap — Status HTTP × Guard (Nama Lengkap) × Error Code × Akibat × Retry × Perbaikan × UI Behavior × Detection

Nama guard yang dipakai:

- **G1** = Idempotency + Unique Constraint
- **G2** = Payment Ledger + Validasi Total
- **G3** = State Machine Transaksi
- **G4** = Atomic Stock Decrement + Reservasi + Stock Movement Ledger
- **G5** = Shift & Cash Drawer Guard
- **G6** = Invoice Numbering + DB Constraint
- **G7** = Audit Log + Authorization Refund/Void/Diskon

(Tetap ditulis lengkap di setiap baris, singkatan hanya untuk header.)

---

| Status | Klasifikasi | Guard | Error Code | Skenario | Akibat jika salah handling | Retry? | Key Reuse? | Perbaikan | UI Behavior | Detection |
|---|---|---|---|---|---|---|---|---|---|---|
| **200** | Success | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | — | Checkout / refund / void sukses | Dianggap gagal → retry dengan key baru → **duplikat sale/payment/refund** | No | — | Terima sebagai sukses. Simpan `sale_id`, `invoice_no`, `request_id`. Jangan retry. | Tampilkan struk, disable tombol bayar | — |
| **201** | Success | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | — | Sale baru dibuat | Dianggap gagal → duplikat | No | — | Terima. Idempotency harus mengembalikan **201 cached** pada retry dengan key sama | Tampilkan struk | `idempotency_hits_total` |
| **202** | Success (async) | Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement | `PAYMENT_PENDING` | QRIS initiate | Client tunggu response akhir → timeout → retry POST → **double charge** | No (poll) | — | Client **polling** `GET /sales/{id}/status`. Retry POST hanya jika `by-client-request` = 404 | "Menunggu pembayaran..." | `pending_payments_age` |
| **301/308** | Definitive Failure | Idempotency + Unique Constraint; State Machine Transaksi; Invoice Numbering | `ENDPOINT_MOVED` | API pindah domain | Client follow redirect → **re-POST** ke endpoint baru → `client_request_id` hilang / diproses 2x | No | — | Server **jangan redirect POST**. Update base URL via config, bukan redirect. Jika terpaksa: preserve method, body, `client_request_id` | "Aplikasi perlu update" | `http_3xx_total` (target = 0) |
| **302/307** | Definitive Failure | Idempotency + Unique Constraint; State Machine Transaksi; Invoice Numbering | `UNEXPECTED_REDIRECT` | LB/proxy redirect | Sama seperti 301 | No | — | Client **jangan follow** untuk POST. Alert developer | "Sistem error, lapor" | `http_3xx_total` > 0 → alert |
| **400** | Definitive Failure | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Audit Log + Authorization | `VALIDATION_ERROR`, `INVALID_AMOUNT` | Payload invalid | Retry loop → log spam, tidak ada progress | No | — | Tampilkan detail field yang salah | Highlight field, tampilkan pesan spesifik | `http_4xx_total{code="400"}` |
| **401** | Definitive Failure | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | `UNAUTHENTICATED` | Token expired | Retry loop → rate limit → shift macet | Sekali (setelah refresh token) | **Same** | Refresh token, retry **sekali** dengan `client_request_id` yang sama | Redirect login | `auth_failures_total` |
| **403** | Definitive Failure | Audit Log + Authorization | `SELF_APPROVAL_FORBIDDEN`, `APPROVAL_REQUIRED`, `ROLE_NOT_ALLOWED` | Aksi sensitif tanpa izin | Kasir retry → tetap 403 → transaksi menggantung | No | — | Minta approval supervisor | Modal PIN supervisor | `approval_requests_total` |
| **404** | Definitive Failure / Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi | `NOT_FOUND`, `SALE_NOT_FOUND`, `PRODUCT_NOT_FOUND` | Resource tidak ada **atau** `by-client-request` tidak ada | Salah interpretasi → duplikat (retry key baru) atau transaksi hilang | `by-client-request`: **Yes (same key)**. Resource: **No** | **Same** (khusus retry POST) | Bedakan konteks: 404 `by-client-request` → aman retry POST key sama. 404 resource → tampilkan error | "Transaksi tidak ditemukan" vs retry otomatis | `http_4xx_total{code="404"}` |
| **405** | Definitive Failure | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | `METHOD_NOT_ALLOWED` | Client salah method | Retry tidak menolong | No | — | Fix client / deploy | "Aplikasi error, lapor" | Alert (deploy bug) |
| **408** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer | `REQUEST_TIMEOUT` | Client lambat kirim | Retry key baru → **duplikat** | Yes (same key, setelah cek status) | **Same** | Cek `by-client-request` dulu. 404 → retry POST key sama. 200 → tampilkan hasil | "Mengecek status..." | `http_4xx_total{code="408"}` |
| **409** | Definitive Failure (state conflict) | Idempotency + Unique Constraint; State Machine Transaksi; Shift & Cash Drawer | `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`, `INVALID_STATE_TRANSITION`, `SALE_ALREADY_PAID`, `VERSION_CONFLICT`, `TERMINAL_SHIFT_TAKEN` | Konflik state / version / idempotency | Retry buta → loop. Anggap gagal → kasir input ulang → **duplikat** | No (buta). `VERSION_CONFLICT`: **reload & re-execute** | — (payload beda = bug client) | Baca `code`: `VERSION_CONFLICT` → reload sale & ulangi dengan version baru. `SALE_ALREADY_PAID` → tampilkan struk lama. `TERMINAL_SHIFT_TAKEN` → pilih terminal lain. `IDEMPOTENCY_KEY_REUSED...` → escalate (bug client) | Reload / tampilkan state sebenarnya / arahkan pilih terminal | `state_transition_rejected_total`, `version_conflict_total` |
| **410** | Definitive Failure | Payment Ledger + Validasi Total; State Machine Transaksi | `GONE` | Resource dihapus (seharusnya **tidak pernah** untuk ledger) | Client retry → loop | No | — | **Bug** — ledger tidak boleh 410. Alert developer | "Hubungi supervisor" | `http_4xx_total{code="410"}` > 0 → alert |
| **422** | Definitive Failure | Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Audit Log + Authorization | `STOCK_INSUFFICIENT`, `STOCK_RESERVATION_EXPIRED`, `SHIFT_NOT_OPEN`, `SHIFT_HAS_PENDING_TRANSACTIONS`, `CASH_TENDERED_LESS_THAN_APPLIED`, `NON_CASH_TENDERED_MISMATCH`, `REFUND_EXCEEDS_CAPTURED`, `VARIANCE_NEEDS_APPROVAL` | Aturan bisnis dilanggar | **Cache 4xx di idempotency** → retry setelah restock tetap 422. Retry loop | No | — (payload beda = key baru) | **Jangan cache 4xx di idempotency.** Rollback hapus row idempotency. Tampilkan penyebab spesifik | "Stok habis" / "Shift belum buka" / "Butuh approval" / "Uang kurang" | `business_rejections_total{code}` |
| **429** | Definitive Failure (sementara) | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | `RATE_LIMITED` | Rate limit | Retry langsung → throttle makin parah | Yes (setelah `Retry-After`) | **Same** | Backoff eksponensial, maks 3x | "Sistem sibuk, coba lagi" | `rate_limit_hits_total` |
| **500** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering | `INTERNAL_ERROR` | Crash sebelum/sesudah commit | **Duplikat** jika retry key baru. Parsial jika multi-commit | Yes (same key, backoff) | **Same** | Retry POST key sama. `INVOICE_SEQUENCE_CONFLICT` **tidak boleh** sampai client — retry internal di server | "Status belum pasti, mengecek..." | `http_5xx_total{code="500"}` > 1% → alert |
| **501** | Definitive Failure | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; State Machine Transaksi; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer; Invoice Numbering; Audit Log + Authorization | `NOT_IMPLEMENTED` | Deploy bug | Retry tidak menolong | No | — | Fix deploy | "Sistem error, lapor" | Alert (deploy) |
| **502** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer | `BAD_GATEWAY` | Proxy crash | Ambigu: commit atau tidak | Yes (same key) | **Same** | Retry POST key sama. Cek `by-client-request` setelah 3 gagal | "Status belum pasti..." | `http_5xx_total{code="502"}` |
| **503** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer | `SERVICE_UNAVAILABLE` | Maintenance / overload | Ambigu | Yes (same key, hormati `Retry-After`) | **Same** | Retry POST key sama, backoff | "Sistem sibuk, coba lagi" | `http_5xx_total{code="503"}` |
| **504** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer | `GATEWAY_TIMEOUT` | Upstream timeout | Ambigu: commit atau tidak | Yes (same key) | **Same** | Retry POST key sama. > 60 detik belum pasti → eskalasi dengan `request_id` | "Status belum pasti. ID: {request_id}" | `http_5xx_total{code="504"}`, `payment_gateway_timeout_total` |
| **Timeout client** | Ambiguous | Idempotency + Unique Constraint; Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement; Shift & Cash Drawer | `CLIENT_TIMEOUT` | Tidak ada response | **Paling berbahaya** — kasir retry key baru → **duplikat** | Yes (same key, setelah cek status) | **Same** | GET `by-client-request`. 404 → retry POST key sama. 200 → tampilkan struk. Jangan pernah re-enable tombol bayar dengan key baru | Tombol bayar **disable** sampai status pasti | `client_timeout_total`, spike `idempotency_hits_total` |

---

## Aturan Retry Ringkas (untuk Retry Interceptor)

| Klasifikasi | Status | Guard Terkait | Retry? | Key Reuse |
|---|---|---|---|---|
| Success | 200, 201 | G1–G7 semua | ❌ | — |
| Success (async) | 202 | Payment Ledger + Validasi Total; Atomic Stock + Reservasi + Stock Movement | ❌ (poll) | — |
| Definitive Failure | 301, 302, 307, 308, 400, 403, 404 (resource), 405, 410, 422, 501 | sesuai baris | ❌ | — |
| Definitive Failure (sementara) | 429 | sesuai baris | ✅ (setelah `Retry-After`) | **Same** |
| Ambiguous | 408, 500, 502, 503, 504, timeout client | G1, G2, G4, G5 | ✅ | **Same** |
| Ambiguous (khusus) | 404 (`by-client-request`) | Idempotency + Unique Constraint | ✅ | **Same** |
| State conflict | 409 | G1, G3, G5 | ❌ (buta). `VERSION_CONFLICT` → reload & **re-execute**, bukan retry | — |
| Auth | 401 | semua | ✅ sekali (setelah refresh) | **Same** |

---

## Catatan Sistematisasi

- **Guard yang muncul di setiap baris** = guard yang **wajib** ada side effect-nya di endpoint tersebut. Kalau sebuah guard tidak muncul, berarti endpoint itu tidak menyentuh domain guard tersebut — dan itu harus disengaja, bukan kelupaan.
- **Baris 3xx**: target operasionalnya `http_3xx_total == 0`. Untuk POS API, 3xx bukan sesuatu yang di-handle client — itu **bug infrastruktur**.
- **`Key Reuse = Same`** wajib untuk semua retry pada status Ambiguous dan 429/401. Ini yang membuat **Idempotency + Unique Constraint** benar-benar bekerja; tanpa `Same`, guard lain tidak menolong.
- **`Jangan cache 4xx`** adalah aturan yang menyentuh **Idempotency + Unique Constraint** langsung — kesalahan paling umum dan paling merusak di POS.