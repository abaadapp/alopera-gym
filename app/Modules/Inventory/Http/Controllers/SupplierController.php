<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Inventory/Suppliers/Index', [
            'suppliers' => Supplier::withCount('purchaseOrders')->orderBy('name')->get()->map(fn ($s) => $s->only('id', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active', 'purchase_orders_count')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return back()->with('success', 'تمت إضافة المورد.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request));

        return back()->with('success', 'تم حفظ المورد.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['is_active' => false]);
        $supplier->delete();

        return back()->with('success', 'تم حذف المورد.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
