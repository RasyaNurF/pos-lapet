<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\SuspendedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Uji',
            'role' => 'kasir',
            'shift_status' => 'open',
        ]);
        $this->kasir->assignRole('kasir');
    }

    // ------------------------------------------------------------------
    // Skema data karyawan
    // ------------------------------------------------------------------

    public function test_employee_fields_exist_with_expected_defaults(): void
    {
        foreach (['phone', 'address', 'hire_date', 'is_active', 'deleted_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "Kolom users.{$column} harus ada.",
            );
        }

        // Karyawan baru aktif secara bawaan — admin tinggal menonaktifkan
        // bila diperlukan, bukan wajib mengaktifkan satu per satu.
        $this->assertTrue($this->kasir->is_active);
        $this->assertNull($this->kasir->phone);
        $this->assertNull($this->kasir->address);
        $this->assertNull($this->kasir->hire_date);
        $this->assertNull($this->kasir->deleted_at);
    }

    public function test_is_active_casts_to_boolean_and_hire_date_to_date(): void
    {
        $this->assertIsBool($this->kasir->is_active);

        $this->kasir->update([
            'phone' => '08123456789',
            'address' => 'Jl. Melati No. 3',
            'hire_date' => '2026-01-15',
            'is_active' => false,
        ]);

        $fresh = $this->kasir->fresh();

        $this->assertFalse($fresh->is_active);
        $this->assertIsBool($fresh->is_active);
        $this->assertSame('2026-01-15', $fresh->hire_date->format('Y-m-d'));
        $this->assertSame('08123456789', $fresh->phone);
        $this->assertSame('Jl. Melati No. 3', $fresh->address);
    }

    /**
     * Karyawan yang dibuat tanpa menyetel status apa pun harus langsung
     * aktif — kolom DB-lah yang menjaminnya. Tanpa default ini, kode mana
     * pun yang lupa mengisi `is_active` akan membuat akun langsung mati.
     */
    public function test_user_created_without_status_defaults_to_active(): void
    {
        $user = User::create([
            'name' => 'Tanpa Status',
            'email' => 'tanpa.status@lapet.test',
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'tanpa.status@lapet.test',
            'is_active' => true,
        ]);
        $this->assertTrue($user->fresh()?->is_active);
    }

    // ------------------------------------------------------------------
    // Jebakan: soft delete menghapus nama dari riwayat
    // ------------------------------------------------------------------

    /**
     * Empat relasi ini memakai withTrashed() — inilah gunanya kolom
     * deleted_at. Tanpanya, menghapus kasir menghapus identitas pelakunya
     * dari seluruh sejarah penjualan toko.
     */
    public function test_deleted_kasir_is_still_named_across_history_relations(): void
    {
        $shift = Shift::create([
            'user_id' => $this->kasir->id,
            'start_time' => now(),
            'start_cash' => 50000,
            'total_sales' => 0,
        ]);

        $transaction = Transaction::create([
            'invoice_no' => 'INV/EMP/0001',
            'user_id' => $this->kasir->id,
            'subtotal' => 100000,
            'discount' => 0,
            'tax' => 11000,
            'tax_rate' => 11,
            'total' => 111000,
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $audit = AuditLog::create([
            'user_id' => $this->kasir->id,
            'action' => 'employee.test',
        ]);

        $suspended = SuspendedTransaction::create([
            'user_id' => $this->kasir->id,
            'cart' => [['id' => 1, 'name' => 'X', 'price' => 1000, 'qty' => 1]],
            'label' => 'Tunda Uji',
        ]);

        $this->kasir->delete();

        $this->assertTrue($this->kasir->trashed(), 'Harus terhapus lunak, bukan permanen.');

        $this->assertSame('Kasir Uji', $shift->fresh()?->user?->name, 'Nama hilang dari riwayat shift.');
        $this->assertSame('Kasir Uji', $transaction->fresh()?->user?->name, 'Nama hilang dari riwayat transaksi.');
        $this->assertSame('Kasir Uji', $audit->fresh()?->user?->name, 'Nama hilang dari jejak audit.');
        $this->assertSame('Kasir Uji', $suspended->fresh()?->user?->name, 'Nama hilang dari kas tunda.');

        // Riwayatnya sendiri tidak ikut terhapus — hanya akunnya.
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
        $this->assertDatabaseHas('suspended_transactions', ['id' => $suspended->id]);
    }

    /**
     * Kontrol negatif: withTrashed() memang yang bekerja, bukan kebetulan
     * barisnya masih terbaca.
     */
    public function test_soft_deleted_user_disappears_from_default_queries(): void
    {
        $this->kasir->delete();

        $this->assertNull(User::find($this->kasir->id), 'Query biasa tidak boleh menemukan yang terhapus.');
        $this->assertSame('Kasir Uji', User::withTrashed()->find($this->kasir->id)?->name);
    }

    // ------------------------------------------------------------------
    // Jebakan: cascade menghapus riwayat saat user dihapus permanen
    // ------------------------------------------------------------------

    public function test_migration_does_not_break_schema_on_sqlite(): void
    {
        foreach (['shifts', 'transactions', 'suspended_transactions', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} harus tetap ada setelah guard FK.");
        }

        // Insert normal tetap diterima — guard tidak menghalangi data sah.
        $shift = Shift::create([
            'user_id' => $this->kasir->id,
            'start_time' => now(),
            'start_cash' => 1000,
            'total_sales' => 0,
        ]);

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    public function test_user_delete_rules_are_restricted_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Pengubahan FK hanya didukung MySQL (SQLite tanpa DROP FOREIGN KEY).');
        }

        $rules = [];
        foreach (DB::select(
            'SELECT TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?',
            ['users'],
        ) as $row) {
            $rules[$row->TABLE_NAME] = $row->DELETE_RULE;
        }

        $this->assertSame('RESTRICT', $rules['shifts'] ?? null, 'shifts harus menolak hapus user bersejarah.');
        $this->assertSame('RESTRICT', $rules['transactions'] ?? null, 'transactions harus menolak hapus user bersejarah.');
        $this->assertSame('RESTRICT', $rules['suspended_transactions'] ?? null, 'suspended_transactions harus menolak.');

        // audit_logs sengaja dibiarkan: jadi NULL, tetap terbaca sebagai jejak.
        $this->assertSame('SET NULL', $rules['audit_logs'] ?? null);
    }

    /**
     * Bukti fungsional guard — di MySQL, menghapus permanen kasir yang punya
     * riwayat langsung ditolak database, bukan menghapus riwayatnya.
     */
    public function test_hard_deleting_kasir_with_history_is_refused_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Pengubahan FK hanya didukung MySQL.');
        }

        Shift::create([
            'user_id' => $this->kasir->id,
            'start_time' => now(),
            'start_cash' => 50000,
            'total_sales' => 0,
        ]);

        $this->expectException(QueryException::class);

        $this->kasir->forceDelete();
    }
}
