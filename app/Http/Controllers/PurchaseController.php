<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\StockLog;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    // List + filter
    public function index(Request $r) {
        $q = Purchase::with(['product','supplier','user','approver'])->latest();

        if ($r->filled('status'))      $q->where('status', $r->status);              // pending|approved|rejected
        if ($r->filled('supplier_id')) $q->where('supplier_id', $r->supplier_id);
        if ($r->filled('product_id'))  $q->where('product_id', $r->product_id);
        if ($r->filled('from'))        $q->whereDate('created_at', '>=', $r->from);
        if ($r->filled('to'))          $q->whereDate('created_at', '<=', $r->to);

        return response()->json($q->paginate(20));
    }

    // Detail
    public function show(Purchase $purchase) {
        return $purchase->load(['product','supplier','user','approver']);
    }

    // BUAT DRAFT PURCHASE (status: pending) — TIDAK MENAMBAH STOK
    public function store(Request $r) {
        $data = $r->validate([
            'product_id'  => 'required|exists:products,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'amount'      => 'required|integer|min:1',
            'price'       => 'required|numeric|min:0',
            'note'        => 'nullable|string',
        ]);

        $data['user_id'] = $r->user()->id;
        $seq = Purchase::lockForUpdate()->count() + 1; // simple sequence; untuk high load pakai table sequences
        $data['purchase_number'] = 'PO-'.now()->format('Ymd').'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

        // Buat pending tanpa perubahan stok
        $purchase = DB::transaction(function () use ($data) {
            return Purchase::create($data + ['status' => 'pending']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Draft purchase dibuat (pending). Lakukan GR untuk menambah stok.',
            'data'    => $purchase
        ], 201);
    }

    // APPROVE (GR) — MENAMBAH STOK & LOG
    public function approve(Request $r, Purchase $purchase) {
        if ($purchase->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase tidak dalam status pending.'
            ], 422);
        }

        DB::transaction(function () use ($r, $purchase) {
            // lock product row to avoid race
            $product = Product::whereKey($purchase->product_id)->lockForUpdate()->first();

            // tambah stok
            $product->increment('stock', $purchase->amount);

            // stock log
            StockLog::create([
                'product_id'  => $product->id,
                'user_id'     => $r->user()->id,
                'change_type' => 'in',
                'quantity'    => $purchase->amount,
                'note'        => 'GR '.$purchase->purchase_number,
            ]);

            // set approved
            $purchase->update([
                'status'      => 'approved',
                'approved_by' => $r->user()->id,
                'approved_at' => now(),
                // boleh simpan note GR dari request kalau dikirim
                'note'        => $r->input('note', $purchase->note),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Purchase disetujui & stok bertambah.',
            'data'    => $purchase->fresh(['product','supplier','user','approver'])
        ]);
    }

    // REJECT — TIDAK MENAMBAH STOK
    public function reject(Request $r, Purchase $purchase) {
        if ($purchase->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase tidak dalam status pending.'
            ], 422);
        }

        $purchase->update([
            'status'      => 'rejected',
            'approved_by' => $r->user()->id,
            'approved_at' => now(),
            'note'        => $r->input('note', $purchase->note),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Purchase ditolak. Stok tidak berubah.',
            'data'    => $purchase->fresh(['product','supplier','user','approver'])
        ]);
    }
}


