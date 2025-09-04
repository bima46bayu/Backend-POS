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
    public function index(Request $request)
    {
        $q = Sale::with(['items.product','cashier','payments'])->latest();

        if ($request->filled('code')) {
            $q->where('code','like','%'.$request->code.'%');
        }
        if ($request->filled('cashier_id')) {
            $q->where('cashier_id', $request->cashier_id);
        }
        if ($request->filled('status')) {
            $q->where('status',$request->status);
        }

        return response()->json($q->paginate(20));
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.product','cashier','payments']);
        return response()->json($sale);
    }

    public function store(StoreSaleRequest $request)
    {
        $user = Auth::user();

        // (opsional) validasi role kasir/admin
        // if (!in_array($user->role, ['kasir','admin'])) abort(403, 'Forbidden');

        return DB::transaction(function () use ($request, $user) {
            $itemsInput = $request->items;

            // Ambil harga & stok produk terkini
            $productIds = collect($itemsInput)->pluck('product_id')->all();
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            $saleItemsPayload = [];

            foreach ($itemsInput as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (!$product) abort(422, "Product {$row['product_id']} not found.");

                $qty = (int) $row['qty'];
                if ($qty < 1) abort(422, 'Qty minimal 1');

                // Cek stok cukup
                if ($product->stock < $qty) {
                    abort(422, "Stok tidak cukup untuk {$product->name} (tersisa {$product->stock})");
                }

                $price = (float) $product->price; // snapshot harga
                $lineSubtotal = $price * $qty;
                $subtotal += $lineSubtotal;

                $saleItemsPayload[] = [
                    'product_id' => $product->id,
                    'price'      => $price,
                    'qty'        => $qty,
                    'subtotal'   => $lineSubtotal,
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

            $discount = (float) ($request->discount ?? 0);
            $tax      = (float) ($request->tax ?? 0);
            $total    = max(0, $subtotal - $discount + $tax);

            // Hitung total pembayaran
            $paid = collect($request->payments)->sum('amount');
            $change = max(0, $paid - $total);

            if ($paid < $total) {
                abort(422, "Pembayaran kurang Rp " . number_format($total - $paid, 0, ',', '.'));
            }

            // Buat kode transaksi
            $code = 'POS-'.now()->format('Ymd').'-'.str_pad((string)(Sale::whereDate('created_at', now())->count()+1), 4, '0', STR_PAD_LEFT);

            $sale = Sale::create([
                'code'          => $code,
                'cashier_id'    => $user->id,
                'customer_name' => $request->customer_name,
                'subtotal'      => $subtotal,
                'discount'      => $discount,
                'tax'           => $tax,
                'total'         => $total,
                'paid'          => $paid,
                'change'        => $change,
                'status'        => 'completed',
            ]);

            // Insert items
            foreach ($saleItemsPayload as $payload) {
                $payload['sale_id'] = $sale->id;
                SaleItem::create($payload);
            }

            // Insert payments
            foreach ($request->payments as $p) {
                SalePayment::create([
                    'sale_id'   => $sale->id,
                    'method'    => $p['method'],
                    'amount'    => $p['amount'],
                    'reference' => $p['reference'] ?? null,
                ]);
            }

            // Update note stock_logs dengan code sale (biar rapi jejaknya)
            StockLog::where('note', 'sale (temp)')
                ->whereIn('product_id', $productIds)
                ->where('user_id', $user->id)
                ->latest()->take(count($saleItemsPayload))
                ->update(['note' => "sale #{$sale->code}"]);

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
