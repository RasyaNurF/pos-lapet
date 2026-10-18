<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->input('search')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::create($request->validated());

        $this->audit->log('customer.create', $customer, null, $customer->only(['name', 'phone', 'email']));

        return back()->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $old = $customer->only(['name', 'phone', 'email']);
        $customer->update($request->validated());

        $this->audit->log('customer.update', $customer, $old, $customer->only(['name', 'phone', 'email']));

        return back()->with('success', 'Pelanggan berhasil diperbarui.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $snapshot = $customer->only(['name', 'phone', 'email']);
        $customer->delete();

        $this->audit->log('customer.delete', $customer, $snapshot, null);

        return back()->with('success', 'Pelanggan berhasil dihapus.');
    }
}
