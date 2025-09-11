<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\StockLog;
use App\Models\Product;
use App\Models\Supplier;

class SupplierController extends Controller {
    public function index() { return Supplier::paginate(20); }
    
    public function store(Request $r) {
        $data = $r->validate(['name'=>'required','contact'=>'nullable']);
        return Supplier::create($data);
    }
    
    public function show(Supplier $supplier) { return $supplier; }
    
    public function update(Request $r, Supplier $supplier) {
        $data = $r->validate(['name'=>'required','contact'=>'nullable']);
        $supplier->update($data);
        return $supplier;
    }
    
    public function destroy(Supplier $supplier) {
        $supplier->delete();
        return response()->json(['message'=>'Deleted']);
    }
}


