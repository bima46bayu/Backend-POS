<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\StockLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;

class SupplierController extends Controller 
{
    // GET /api/suppliers?search=&type=&sort_by=name&sort_dir=asc&per_page=15
    public function index(Request $request)
    {
        $q = Supplier::query();

        // Pencarian umum (name, contact, phone, email)
        if ($search = $request->query('search')) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('contact', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter type (kalau kolomnya ada)
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }

        // Sorting
        $sortBy  = $request->query('sort_by', 'name');
        $sortDir = $request->query('sort_dir', 'asc');
        $allowedSort = ['id','name','type','created_at']; // batasi agar aman
        if (!in_array($sortBy, $allowedSort)) $sortBy = 'name';
        if (!in_array(strtolower($sortDir), ['asc','desc'])) $sortDir = 'asc';
        $q->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 100 ? 100 : ($perPage < 1 ? 15 : $perPage);

        return response()->json($q->paginate($perPage));
    }

    // POST /api/suppliers
    public function store(StoreSupplierRequest $request)
    {
        $supplier = Supplier::create($request->validated());
        return response()->json($supplier, 201);
    }

    // GET /api/suppliers/{id}
    public function show(Supplier $supplier)
    {
        return response()->json($supplier);
    }

    // PUT/PATCH /api/suppliers/{id}
    public function update(SupplierUpdateRequest $request, Supplier $supplier)
    {
        $supplier->update($request->validated());
        return response()->json($supplier);
    }

    // DELETE /api/suppliers/{id}
    public function destroy(Supplier $supplier)
    {
        // jika pakai soft delete:
        // $supplier->delete();
        // return response()->json(['message' => 'Supplier archived']);

        // hard delete:
        $supplier->delete();
        return response()->json(['message' => 'Supplier deleted']);
    }
}


