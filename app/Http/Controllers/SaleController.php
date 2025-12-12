<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\Http\Requests\StoreSaleRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\StockLog;
use Illuminate\Support\Facades\Schema;

use App\Services\InventoryService;

class SaleController extends Controller
{
    public function index(Request $r)
    {
        // tambahkan storeLocation di eager load
        $q = Sale::with(['items.product', 'cashier', 'storeLocation', 'payments'])
            ->latest('id');

        if ($r->filled('code'))       $q->where('code', 'like', '%' . $r->code . '%');
        if ($r->filled('cashier_id')) $q->where('cashier_id', $r->cashier_id);
        if ($r->filled('status'))     $q->where('status', $r->status);
        if ($r->filled('from'))       $q->whereDate('created_at', '>=', $r->from);
        if ($r->filled('to'))         $q->whereDate('created_at', '<=', $r->to);

        // 🔥 sekarang langsung pakai kolom sales.store_location_id (lebih cepat)
        if ($r->filled('store_location_id')) {
            $q->where('store_location_id', $r->store_location_id);
        }

        if ($r->boolean('only_discount')) {
            $q->where(function ($qq) {
                $qq->where('discount', '>', 0)
                    ->orWhereHas('items', function ($qi) {
                        $qi->where('discount_nominal', '>', 0);
                    });
            });
        }

        $perPage = (int)($r->per_page ?? 10);
        $perPage = max(1, min(50000, $perPage));

        return response()->json($q->paginate($perPage));
    }

    public function show(Sale $sale)
    {
        // tambahkan storeLocation di detail
        $sale->load(['items.product', 'cashier', 'storeLocation', 'payments']);
        return response()->json($sale);
    }

    public function store(StoreSaleRequest $request)
    {
        $user = Auth::user();

        // pastikan store
        $storeId = $request->input('store_location_id') ?? optional($user)->store_location_id;
        if (!$storeId) abort(422, 'store_location_id wajib (atau set store aktif pada user).');

        return DB::transaction(function () use ($request, $user, $storeId) {
            $itemsInput = $request->items ?? [];
            if (empty($itemsInput)) abort(422, 'Items tidak boleh kosong');

            // lock produk
            $productIds = collect($itemsInput)->pluck('product_id')->all();
            $products   = Product::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0.0;
            $saleItemsPayload = [];

            foreach ($itemsInput as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (!$product) abort(422, "Product {$row['product_id']} not found.");

                $qty = (int)($row['qty'] ?? 0);
                if ($qty < 1) abort(422, 'Qty minimal 1');

                // VALIDASI stok (legacy: pakai products.stock seperti kodenya sekarang)
                if ($product->stock < $qty) {
                    abort(422, "Stok tidak cukup untuk {$product->name} (tersisa {$product->stock})");
                }

                $unitPrice = (float)($row['unit_price'] ?? $product->price);
                $discUnit  = round((float)($row['discount_nominal'] ?? 0), 2);
                $discUnit  = max(0, min($discUnit, $unitPrice));

                $netUnit   = round($unitPrice - $discUnit, 2);
                $lineTotal = round($netUnit * $qty, 2);

                $subtotal += $lineTotal;

                $saleItemsPayload[] = [
                    'product_id'       => $product->id,
                    'unit_price'       => $unitPrice,
                    'discount_nominal' => $discUnit,
                    'net_unit_price'   => $netUnit,
                    'qty'              => $qty,
                    'line_total'       => $lineTotal,
                ];

                // legacy: turunkan stock + log
                $product->decrement('stock', $qty);

                StockLog::create([
                    'product_id'  => $product->id,
                    'user_id'     => $user->id,
                    'change_type' => 'out',
                    'quantity'    => $qty,
                    'note'        => 'sale (temp)',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // header amounts
            $extraDiscount = max(0, min(round((float)($request->discount ?? 0), 2), $subtotal));
            $serviceCharge = round((float)($request->service_charge ?? 0), 2);
            $taxPercent    = $request->filled('tax_percent') ? (float)$request->tax_percent : null;
            $taxBase       = max(0, $subtotal - $extraDiscount + $serviceCharge);
            $tax           = $taxPercent !== null ? round($taxBase * ($taxPercent / 100), 2) : round((float)($request->tax ?? 0), 2);
            $total         = round($taxBase + $tax, 2);

            // payments
            $payments = $request->payments ?? [];
            if (!is_array($payments) || empty($payments)) abort(422, 'Payments tidak boleh kosong');

            $paid = round(array_reduce($payments, fn($c, $p) => $c + (float)($p['amount'] ?? 0), 0.0), 2);
            $change = round(max(0, $paid - $total), 2);
            if ($paid < $total) abort(422, "Pembayaran kurang Rp " . number_format($total - $paid, 0, ',', '.'));

            // generate code
            $seq  = Sale::whereDate('created_at', now())->lockForUpdate()->count() + 1;
            $code = 'POS-' . now()->format('Ymd') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

            // simpan sale
            $sale = Sale::create([
                'code'              => $code,
                'cashier_id'        => $user->id,
                'store_location_id' => $storeId,
                'customer_name'     => $request->customer_name,
                'subtotal'          => $subtotal,
                'discount'          => $extraDiscount,
                'service_charge'    => $serviceCharge,
                'tax'               => $tax,
                'total'             => $total,
                'paid'              => $paid,
                'change'            => $change,
                'status'            => 'completed',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // simpan items + konsumsi FIFO + LEDGER OUT per consumption
            /** @var InventoryService $inv */
            $inv = app(InventoryService::class);

            foreach ($saleItemsPayload as $payload) {
                $payload['sale_id'] = $sale->id;
                $item = SaleItem::create($payload);

                // konsumsi stok (pakai servicemu apa adanya)
                if (method_exists($inv, 'consumeFIFOWithPricing')) {
                    $inv->consumeFIFOWithPricing([
                        'product_id'        => $item->product_id,
                        'qty'               => (float)$item->qty,
                        'store_location_id' => $storeId,
                        'sale_id'           => $sale->id,
                        'sale_item_id'      => $item->id,
                        'sale_unit_price'   => (float)$item->net_unit_price,
                        'user_id'           => $user->id ?? null,
                    ]);
                } else {
                    $inv->consumeStock([
                        'product_id'        => $item->product_id,
                        'store_location_id' => $storeId,
                        'qty'               => (float)$item->qty,
                        'source_type'       => 'sale',
                        'source_id'         => $sale->id,
                        'sale_item_id'      => $item->id,
                    ]);
                }

                // ===== LEDGER OUT dari inventory_consumptions (direction = -1, ref_type = 'SALE') =====
                // Utamakan filter by sale_item_id; fallback ke sale_id + product_id jika kolom sale_item_id tidak ada.
                $consQuery = DB::table('inventory_consumptions')->where('product_id', $item->product_id);
                if (Schema::hasColumn('inventory_consumptions', 'sale_item_id')) {
                    $consQuery->where('sale_item_id', $item->id);
                } else {
                    $consQuery->where('sale_id', $sale->id);
                }
                $consRows = $consQuery->orderBy('id')->get(['layer_id', 'qty', 'unit_cost']);

                foreach ($consRows as $c) {
                    DB::table('stock_ledger')->insert([
                        'product_id'        => $item->product_id,
                        'store_location_id' => $storeId,
                        'layer_id'          => $c->layer_id,
                        'user_id'           => $user->id ?? null,
                        'ref_type'          => 'SALE',         // UPPERCASE agar match InventoryController
                        'ref_id'            => $sale->id,
                        'direction'         => -1,             // OUT = -1
                        'qty'               => (float)$c->qty,
                        'unit_cost'         => (float)$c->unit_cost,        // cost dari layer
                        'unit_price'        => (float)$item->net_unit_price, // optional untuk analisa margin
                        'subtotal_cost'     => (float)$c->qty * (float)$c->unit_cost,
                        'note'              => "sale #{$sale->code}",
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }
                // ===== END LEDGER OUT =====
            }

            // simpan payments
            foreach ($payments as $p) {
                SalePayment::create([
                    'sale_id'   => $sale->id,
                    'method'    => strtoupper($p['method']) === 'QRIS' ? 'QRIS' : $p['method'],
                    'amount'    => $p['amount'],
                    'reference' => $p['reference'] ?? null,
                    'created_at'=> now(),
                    'updated_at'=> now(),
                ]);
            }

            // update catatan StockLog sementara
            StockLog::where('note', 'sale (temp)')
                ->whereIn('product_id', $productIds)
                ->where('user_id', $user->id)
                ->latest()
                ->take(count($saleItemsPayload))
                ->update(['note' => "sale #{$sale->code}"]);

            return response()->json(
                // tambahkan storeLocation di response create
                $sale->load(['items.product', 'payments', 'cashier', 'storeLocation']),
                201
            );
        });
    }

    public function void(Sale $sale)
    {
        if ($sale->status === 'void') {
            return response()->json(['message' => 'Sale already void'], 422);
        }

        return DB::transaction(function () use ($sale) {
            $sale->load('items.product', 'cashier');

            // 1) Ambil konsumsi untuk sale ini, kunci supaya konsisten
            $cons = DB::table('inventory_consumptions')
                ->where('sale_id', $sale->id)
                ->whereNull('reversed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($cons as $row) {
                // 2) Kembalikan qty ke layer
                $layer = DB::table('inventory_layers')
                    ->where('id', $row->layer_id)
                    ->lockForUpdate()
                    ->first();

                if ($layer) {
                    // originalQty fallback: qty_initial -> qty -> (qty_remaining + consumed)
                    $originalQty = null;
                    if (isset($layer->qty_initial)) {
                        $originalQty = (float)$layer->qty_initial;
                    } elseif (isset($layer->qty)) {
                        $originalQty = (float)$layer->qty;
                    } else {
                        // fallback konservatif jika skema tidak punya qty/qty_initial
                        $originalQty = (float)$layer->qty_remaining + (float)$row->qty;
                    }

                    $qtyRemaining = (float)($layer->qty_remaining ?? 0);
                    $newRemaining = min($originalQty, $qtyRemaining + (float)$row->qty);

                    DB::table('inventory_layers')->where('id', $layer->id)->update([
                        'qty_remaining' => $newRemaining,
                        'updated_at'    => now(),
                    ]);

                    // 3) Ledger kompensasi (IN) untuk void (append-only)
                    if (Schema::hasTable('stock_ledger')) {
                        // unit_cost ambil dari consumption kalau ada; kalau tidak, fallback dari layer
                        $unitCost = 0.0;
                        if (Schema::hasColumn('inventory_consumptions', 'unit_cost') && isset($row->unit_cost)) {
                            $unitCost = (float)$row->unit_cost;
                        } else {
                            if (Schema::hasColumn('inventory_layers', 'unit_landed_cost') && isset($layer->unit_landed_cost)) {
                                $unitCost = (float)$layer->unit_landed_cost;
                            } elseif (Schema::hasColumn('inventory_layers', 'unit_cost') && isset($layer->unit_cost)) {
                                $unitCost = (float)$layer->unit_cost;
                            } elseif (Schema::hasColumn('inventory_layers', 'unit_price') && isset($layer->unit_price)) {
                                $unitCost = (float)$layer->unit_price;
                            }
                        }

                        DB::table('stock_ledger')->insert([
                            'product_id'        => (int)$row->product_id,
                            'store_location_id' => isset($layer->store_location_id) ? ($layer->store_location_id ?? null) : null,
                            'layer_id'          => (int)$row->layer_id,
                            'user_id'           => auth()->id(),
                            'ref_type'          => 'SALE_VOID',
                            'ref_id'            => $sale->id,
                            'direction'         => +1, // IN kompensasi
                            'qty'               => (float)$row->qty,
                            'unit_cost'         => $unitCost,
                            'unit_price'        => null,
                            'subtotal_cost'     => ((float)$row->qty) * $unitCost,
                            'note'              => "void sale #{$sale->code}",
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }
                }
            }

            // 4) Tandai konsumsi sudah di-reverse (jangan dihapus)
            DB::table('inventory_consumptions')
                ->where('sale_id', $sale->id)
                ->whereNull('reversed_at')
                ->update(['reversed_at' => now(), 'updated_at' => now()]);

            // 5) Kembalikan counter stok produk (legacy) + log
            foreach ($sale->items as $item) {
                $item->product->increment('stock', $item->qty);

                StockLog::create([
                    'product_id'  => $item->product_id,
                    'user_id'     => auth()->id(),
                    'change_type' => 'in',
                    'quantity'    => $item->qty,
                    'note'        => "void sale #{$sale->code}",
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // 6) Status sale
            $sale->update(['status' => 'void']);

            return response()->json([
                'message' => 'Sale voided',
                'sale'    => $sale->fresh(['items.product', 'payments', 'cashier'])
            ]);
        });
    }

    public function fifoBreakdown(Sale $sale)
    {
        $sale->load('items');

        $items = $sale->items->map(function ($it) {
            $cons = DB::table('inventory_consumptions')
                ->where('sale_item_id', $it->id)
                ->selectRaw('SUM(qty) as qty_consumed, SUM(qty * unit_cost) as cogs_total, AVG(unit_cost) as avg_cost')
                ->first();

            $qtySold   = (float)$it->qty;
            $netUnit   = (float)$it->net_unit_price;
            $revenue   = $netUnit * $qtySold;
            $cogs      = (float)($cons->cogs_total ?? 0);
            $avgCost   = (float)($cons->avg_cost ?? 0);
            $gross     = $revenue - $cogs;

            return [
                'sale_item_id'   => $it->id,
                'product_id'     => $it->product_id,
                'qty_sold'       => $qtySold,
                'unit_sale'      => $netUnit,
                'revenue'        => round($revenue, 2),
                'cogs_total'     => round($cogs, 2),
                'avg_unit_cost'  => round($avgCost, 2),
                'gross'          => round($gross, 2),
            ];
        });

        $summary = [
            'revenue' => round($items->sum('revenue'), 2),
            'cogs'    => round($items->sum('cogs_total'), 2),
            'gross'   => round($items->sum('gross'), 2),
        ];

        return response()->json([
            'sale_id' => $sale->id,
            'code'    => $sale->code,
            'items'   => $items,
            'summary' => $summary,
        ]);
    }
}
