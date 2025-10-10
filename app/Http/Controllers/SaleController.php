<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\StockLog;
use App\Http\Requests\StoreSaleRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class SaleController extends Controller
{
// app/Http/Controllers/SalesController.php
    public function index(Request $r)
    {
        $q = \App\Models\Sale::with(['items.product','cashier.storeLocation','payments'])
            ->latest('id');

        if ($r->filled('code')) {
            $q->where('code','like','%'.$r->code.'%');
        }
        if ($r->filled('cashier_id')) {
            $q->where('cashier_id', $r->cashier_id);
        }
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }

        // filter tanggal (created_at)
        if ($r->filled('from')) {
            $q->whereDate('created_at','>=',$r->from);
        }
        if ($r->filled('to')) {
            $q->whereDate('created_at','<=',$r->to);
        }

        // filter cabang via relasi cashier->store_location_id
        if ($r->filled('store_location_id')) {
            $storeId = $r->store_location_id;
            $q->whereHas('cashier', function($qq) use ($storeId) {
                $qq->where('store_location_id', $storeId);
            });
        }

        // hanya transaksi yang ada diskonnya (di header ATAU item)
        if ($r->boolean('only_discount')) {
            $q->where(function($qq){
                $qq->where('discount', '>', 0)
                ->orWhereHas('items', function($qi){
                    $qi->where('discount_nominal','>',0);
                });
            });
        }

        // izinkan per_page, tapi batasi agar aman
        $perPage = (int) ($r->per_page ?? 10);
        $perPage = max(1, min(50000, $perPage));

        return response()->json($q->paginate($perPage));
    }


    public function show(Sale $sale)
    {
        $sale->load(['items.product','cashier','payments']);
        return response()->json($sale);
    }

    public function store(StoreSaleRequest $request)
    {
        $user = Auth::user();
        // if (!in_array($user->role, ['kasir','admin'])) abort(403, 'Forbidden');

        return DB::transaction(function () use ($request, $user) {
            $itemsInput = $request->items ?? [];
            if (empty($itemsInput)) {
                abort(422, 'Items tidak boleh kosong');
            }

            // Ambil produk & kunci stok
            $productIds = collect($itemsInput)->pluck('product_id')->all();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0.0;
            $saleItemsPayload = [];

            foreach ($itemsInput as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (!$product) abort(422, "Product {$row['product_id']} not found.");

                $qty = (int) ($row['qty'] ?? 0);
                if ($qty < 1) abort(422, 'Qty minimal 1');

                // Cek stok
                if ($product->stock < $qty) {
                    abort(422, "Stok tidak cukup untuk {$product->name} (tersisa {$product->stock})");
                }

                // Snapshot harga unit dari produk (atau boleh override dari request jika kamu izinkan)
                $unitPrice = (float) ($row['unit_price'] ?? $product->price);

                // Diskon nominal PER UNIT dari request (frontend sudah konversi)
                $discUnit = round((float)($row['discount_nominal'] ?? 0), 2);
                // Clamp agar tidak melebihi harga unit
                $discUnit = max(0, min($discUnit, $unitPrice));

                $netUnit   = round($unitPrice - $discUnit, 2);
                $lineTotal = round($netUnit * $qty, 2);

                $subtotal += $lineTotal;

                $saleItemsPayload[] = [
                    'product_id'       => $product->id,
                    'unit_price'       => $unitPrice,
                    'discount_nominal' => $discUnit,   // per unit
                    'net_unit_price'   => $netUnit,
                    'qty'              => $qty,
                    'line_total'       => $lineTotal,
                ];

                // Kurangi stok & catat log OUT
                $product->decrement('stock', $qty);

                StockLog::create([
                    'product_id'  => $product->id,
                    'user_id'     => $user->id,
                    'change_type' => 'out',
                    'quantity'    => $qty,
                    'note'        => 'sale (temp)',
                ]);
            }

            // Header adjustments
            $extraDiscount = round((float)($request->discount ?? 0), 2);         // diskon header (Rp)
            $extraDiscount = max(0, min($extraDiscount, $subtotal));             // tidak boleh melebihi subtotal

            $serviceCharge = round((float)($request->service_charge ?? 0), 2);   // biaya layanan (Rp, optional)

            // Pajak: dukung dua cara:
            // 1) tax_percent -> hitung dari (subtotal - discount + service_charge)
            // 2) tax (nominal) -> pakai langsung jika tax_percent tidak diisi
            $taxPercent = $request->filled('tax_percent') ? (float)$request->tax_percent : null;
            $taxBase    = max(0, $subtotal - $extraDiscount + $serviceCharge);
            if ($taxPercent !== null) {
                $tax = round($taxBase * ($taxPercent / 100), 2);
            } else {
                $tax = round((float)($request->tax ?? 0), 2);
            }

            $total = round($taxBase + $tax, 2);

            // Pembayaran (array payments: [{method, amount, reference?}])
            $payments = $request->payments ?? [];
            if (!is_array($payments) || empty($payments)) {
                abort(422, 'Payments tidak boleh kosong');
            }
            $paid = 0.0;
            foreach ($payments as $p) {
                $paid += (float)($p['amount'] ?? 0);
            }
            $paid   = round($paid, 2);
            $change = round(max(0, $paid - $total), 2);

            if ($paid < $total) {
                abort(422, "Pembayaran kurang Rp " . number_format($total - $paid, 0, ',', '.'));
            }

            // Generate Kode
            $seq = Sale::whereDate('created_at', now())->lockForUpdate()->count() + 1;
            $code = 'POS-' . now()->format('Ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // Simpan header sale
            $sale = Sale::create([
                'code'          => $code,
                'cashier_id'    => $user->id,
                'customer_name' => $request->customer_name,
                'subtotal'      => $subtotal,          // setelah diskon item
                'discount'      => $extraDiscount,     // diskon header
                'service_charge'=> $serviceCharge,     // NEW
                'tax'           => $tax,               // nominal tax
                'total'         => $total,
                'paid'          => $paid,
                'change'        => $change,
                'status'        => 'completed',
            ]);

            // Simpan items
            foreach ($saleItemsPayload as $payload) {
                $payload['sale_id'] = $sale->id;
                SaleItem::create($payload);
            }

            // Simpan payments
            foreach ($payments as $p) {
                SalePayment::create([
                    'sale_id'   => $sale->id,
                    // Pastikan enum method konsisten dengan migrasi: ['cash','card','ewallet','transfer','QRIS']
                    // Jika frontend kirim 'qris' kecil, normalisasi di sini:
                    'method'    => strtoupper($p['method']) === 'QRIS' ? 'QRIS' : $p['method'],
                    'amount'    => $p['amount'],
                    'reference' => $p['reference'] ?? null,
                ]);
            }

            // Update note stock_logs → pakai code sale
            StockLog::where('note', 'sale (temp)')
                ->whereIn('product_id', $productIds)
                ->where('user_id', $user->id)
                ->latest()
                ->take(count($saleItemsPayload))
                ->update(['note' => "sale #{$sale->code}"]);

            // Response lengkap
            return response()->json($sale->load(['items.product','payments','cashier']), 201);
        });
    }


    // OPTIONAL: void/cancel sale (kembalikan stok)
    public function void(Sale $sale, Request $request)
    {
        if ($sale->status === 'void') {
            return response()->json(['message' => 'Sale already void'], 422);
        }

        // (opsional) hanya admin boleh void
        // if (Auth::user()->role !== 'admin') abort(403, 'Only admin can void');

        return DB::transaction(function () use ($sale, $request) {
            $sale->load('items.product');

            foreach ($sale->items as $item) {
                $item->product->increment('stock', $item->qty);

                StockLog::create([
                    'product_id'  => $item->product_id,
                    'user_id'     => Auth::id(),
                    'change_type' => 'in',
                    'quantity'    => $item->qty,
                    'note'        => "void sale #{$sale->code}",
                ]);
            }

            $sale->update(['status' => 'void']);

            return response()->json(['message' => 'Sale voided', 'sale' => $sale]);
        });
    }
}
