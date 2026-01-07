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
use App\Models\Discount;
use App\Models\AdditionalCharge;

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

        // ===== store =====
        $storeId = $request->input('store_location_id') ?? optional($user)->store_location_id;
        if (!$storeId) {
            abort(422, 'store_location_id wajib.');
        }

        return DB::transaction(function () use ($request, $user, $storeId) {

            $itemsInput = $request->items ?? [];
            if (empty($itemsInput)) {
                abort(422, 'Items tidak boleh kosong');
            }

            /* =====================================================
            * LOCK PRODUCTS
            * ===================================================== */
            $productIds = collect($itemsInput)->pluck('product_id')->all();
            $products = Product::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /* =====================================================
            * PRELOAD DISCOUNTS (ITEM + GLOBAL)
            * ===================================================== */
            $needIds = [];

            if ($request->filled('global_discount_id')) {
                $needIds[] = (int)$request->global_discount_id;
            }

            foreach ($itemsInput as $it) {
                if (!empty($it['discount_id'])) {
                    $needIds[] = (int)$it['discount_id'];
                }
            }

            $discountMap = Discount::whereIn('id', array_unique($needIds))
                ->where('active', 1)
                ->get()
                ->keyBy('id');

            /* =====================================================
            * HITUNG ITEMS (ITEM DISCOUNT)
            * ===================================================== */
            $subtotal = 0.0;
            $saleItemsPayload = [];

            foreach ($itemsInput as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (!$product) abort(422, "Product {$row['product_id']} tidak ditemukan");

                $qty = (int)($row['qty'] ?? 0);
                if ($qty < 1) abort(422, 'Qty minimal 1');

                if ($product->isStockTracked() && $product->stock < $qty) {
                    abort(422, "Stok {$product->name} tidak cukup (tersisa {$product->stock})");
                }

                $unitPrice = (float)($row['unit_price'] ?? $product->price);
                $lineBase  = $unitPrice * $qty;

                // ---------- ITEM DISCOUNT ----------
                $discNominal = 0.0;
                $disc = null;

                if (!empty($row['discount_id'])) {
                    $disc = $discountMap[(int)$row['discount_id']] ?? null;

                    if ($disc && $disc->scope !== 'ITEM') {
                        abort(422, "Discount item tidak valid (scope bukan ITEM)");
                    }

                    if ($disc) {
                        if ($disc->min_subtotal !== null && $lineBase < (float)$disc->min_subtotal) {
                            abort(422, "Diskon item '{$disc->name}' belum memenuhi minimal pembelian");
                        }

                        if ($disc->kind === 'PERCENT') {
                            $discNominal = $lineBase * ((float)$disc->value / 100);
                            if ($disc->max_amount !== null) {
                                $discNominal = min($discNominal, (float)$disc->max_amount);
                            }
                        } else {
                            $discNominal = (float)$disc->value;
                        }
                    }
                }

                $discNominal = max(0.0, min($discNominal, $lineBase));

                $netLine = $lineBase - $discNominal;
                $netUnit = $netLine / $qty;

                $subtotal += $netLine;

                $saleItemsPayload[] = [
                    'product_id'       => $product->id,
                    'unit_price'       => round($unitPrice, 2),
                    'qty'              => $qty,

                    // 🔥 SNAPSHOT DISKON ITEM
                    'discount_id'      => $disc?->id,
                    'discount_name'    => $disc?->name,
                    'discount_kind'    => $disc?->kind,
                    'discount_value'   => $disc?->value,
                    'discount_nominal' => round($discNominal, 2),

                    'net_unit_price'   => round($netUnit, 2),
                    'line_total'       => round($netLine, 2),
                ];

                if ($product->isStockTracked()) {
                    $product->decrement('stock', $qty);

                    StockLog::create([
                        'product_id'  => $product->id,
                        'user_id'     => $user->id,
                        'change_type' => 'out',
                        'quantity'    => $qty,
                        'note'        => 'sale (temp)',
                    ]);
                }
            }

            /* =====================================================
            * GLOBAL DISCOUNT
            * ===================================================== */
            $globalDiscount = 0.0;
            $global = null;

            if ($request->filled('global_discount_id')) {
                $global = $discountMap[(int)$request->global_discount_id] ?? null;

                if ($global && $global->scope !== 'GLOBAL') {
                    abort(422, "Discount global tidak valid");
                }

                if ($global) {
                    if ($global->min_subtotal !== null && $subtotal < (float)$global->min_subtotal) {
                        abort(422, "Diskon '{$global->name}' belum memenuhi minimal belanja");
                    }

                    if ($global->kind === 'PERCENT') {
                        $globalDiscount = $subtotal * ((float)$global->value / 100);
                        if ($global->max_amount !== null) {
                            $globalDiscount = min($globalDiscount, (float)$global->max_amount);
                        }
                    } else {
                        $globalDiscount = (float)$global->value;
                    }
                }
            }

            $globalDiscount = max(0.0, min($globalDiscount, $subtotal));

            /* =====================================================
            * GRAND TOTAL (SETELAH DISKON)
            * ===================================================== */
            $grandTotal = round($subtotal - $globalDiscount, 2);

            /* =====================================================
            * ADDITIONAL CHARGES (PB1 & SERVICE)
            * 🔥 INI BAGIAN BARU
            * ===================================================== */
            $additionalCharges = AdditionalCharge::where('store_location_id', $storeId)
                ->where('is_active', true)
                ->get();

            $additionalSnapshot = [];
            $additionalTotal = 0.0;

            foreach ($additionalCharges as $c) {
                if ($c->calc_type === 'PERCENT') {
                    $amount = $grandTotal * ($c->value / 100);
                } else {
                    $amount = $c->value;
                }

                $amount = round($amount, 2);
                $additionalTotal += $amount;

                $additionalSnapshot[] = [
                    'type'      => $c->type,          // PB1 / SERVICE
                    'calc_type' => $c->calc_type,     // PERCENT / FIXED
                    'value'     => (float)$c->value,
                    'base'      => $grandTotal,       // 🔥 penting
                    'amount'    => $amount,
                ];
            }

            /* =====================================================
            * TOTAL FINAL
            * ===================================================== */
            $total = round($grandTotal + $additionalTotal, 2);

            /* =====================================================
            * PAYMENTS
            * ===================================================== */
            $payments = $request->payments ?? [];
            if (empty($payments)) abort(422, 'Payments tidak boleh kosong');

            $paid = round(array_reduce(
                $payments,
                fn ($s, $p) => $s + (float)($p['amount'] ?? 0),
                0.0
            ), 2);

            if ($paid < $total) {
                abort(422, "Pembayaran kurang Rp " . number_format($total - $paid, 0, ',', '.'));
            }

            $change = round(max(0.0, $paid - $total), 2);

            /* =====================================================
            * SAVE SALE
            * ===================================================== */
            $seq = Sale::whereDate('created_at', now())
                ->lockForUpdate()
                ->count() + 1;

            $code = 'POS-' . now()->format('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $sale = Sale::create([
                'code'              => $code,
                'cashier_id'        => $user->id,
                'store_location_id' => $storeId,
                'customer_name'     => $request->customer_name ?? 'General',

                'subtotal'          => $subtotal,

                // snapshot global discount
                'discount_id'       => $global?->id,
                'discount_name'     => $global?->name,
                'discount_kind'     => $global?->kind,
                'discount_value'    => $global?->value,
                'discount'          => $globalDiscount,

                // 🔥 core baru
                'grand_total'       => $grandTotal,
                'additional_charges_snapshot' => $additionalSnapshot,
                'additional_charge_total'     => $additionalTotal,
                'final_total'       => $total,

                // legacy (biarkan sinkron)
                'total'             => $total,

                'paid'              => $paid,
                'change'            => $change,
                'status'            => 'completed',
            ]);

            /* =====================================================
            * SAVE ITEMS + FIFO + STOCK LEDGER (CARA KEMARIN)
            * ===================================================== */
            $inv = app(InventoryService::class);

            foreach ($saleItemsPayload as $payload) {
                $payload['sale_id'] = $sale->id;
                $item = SaleItem::create($payload);

                $product = $products[$item->product_id] ?? null;

                if ($product && $product->isStockTracked()) {

                    // 1️⃣ FIFO (tetap seperti sebelumnya)
                    $inv->consumeFIFOWithPricing([
                        'product_id'        => $item->product_id,
                        'store_location_id' => $storeId,
                        'qty'               => (float)$item->qty,
                        'sale_id'           => $sale->id,
                        'sale_item_id'      => $item->id,
                        'sale_unit_price'   => (float)$item->net_unit_price,
                        'user_id'           => $user->id,
                    ]);

                    // 2️⃣ AMBIL INVENTORY CONSUMPTIONS (SUMBER LEDGER)
                    $consQuery = DB::table('inventory_consumptions')
                        ->where('product_id', $item->product_id);

                    if (Schema::hasColumn('inventory_consumptions', 'sale_item_id')) {
                        $consQuery->where('sale_item_id', $item->id);
                    } else {
                        $consQuery->where('sale_id', $sale->id);
                    }

                    $consRows = $consQuery
                        ->orderBy('id')
                        ->get(['layer_id', 'qty', 'unit_cost']);

                    // 3️⃣ TULIS STOCK LEDGER (OUT)
                    foreach ($consRows as $c) {
                        DB::table('stock_ledger')->insert([
                            'product_id'        => $item->product_id,
                            'store_location_id' => $storeId,
                            'layer_id'          => $c->layer_id,
                            'user_id'           => $user->id ?? null,

                            'ref_type'          => 'SALE',
                            'ref_id'            => $sale->id,

                            'direction'         => -1, // OUT
                            'qty'               => (float)$c->qty,
                            'unit_cost'         => (float)$c->unit_cost,

                            // opsional tapi DIPAKAI KEMARIN
                            'unit_price'        => (float)$item->net_unit_price,
                            'subtotal_cost'     => (float)$c->qty * (float)$c->unit_cost,

                            'note'              => "sale #{$sale->code}",
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }
                }
            }

            foreach ($payments as $p) {
                SalePayment::create([
                    'sale_id'   => $sale->id,
                    'method'    => strtoupper($p['method']) === 'QRIS' ? 'QRIS' : $p['method'],
                    'amount'    => $p['amount'],
                    'reference' => $p['reference'] ?? null,
                ]);
            }

            StockLog::where('note', 'sale (temp)')
                ->where('user_id', $user->id)
                ->update(['note' => "sale #{$sale->code}"]);

            return response()->json(
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

            // 5) Kembalikan counter stok produk (legacy) + log HANYA untuk produk stock
            foreach ($sale->items as $item) {
                $product = $item->product;

                if ($product && $product->isStockTracked()) {
                    $product->increment('stock', $item->qty);

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
