<?php

// namespace App\Http\Controllers;

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Validation\ValidationException;

// use App\Models\Discount;
// use App\Models\Sale;
// use App\Models\SaleItem;
// use App\Models\Product;

// class PosCheckoutController extends Controller
// {
//     public function checkout(Request $r)
//     {
//         $data = $r->validate([
//             'cashier_id'        => 'required|integer',
//             'store_location_id' => 'required|integer',
//             'customer_name'     => 'nullable|string|max:120',

//             // jika kamu punya kolom ini di sales:
//             'service_charge'    => 'nullable|numeric|min:0',
//             'tax'               => 'nullable|numeric|min:0',

//             // diskon global dari dropdown (opsional)
//             'discount_id'       => 'nullable|integer|exists:discounts,id',

//             // items
//             'items'                 => 'required|array|min:1',
//             'items.*.product_id'    => 'required|integer',
//             'items.*.qty'           => 'required|numeric|min:0.0001',
//             'items.*.unit_price'    => 'required|numeric|min:0',
//             // diskon item dari dropdown (opsional)
//             'items.*.discount_id'   => 'nullable|integer|exists:discounts,id',
//         ]);

//         return DB::transaction(function () use ($data) {
//             $service = (float)($data['service_charge'] ?? 0);
//             $tax     = (float)($data['tax'] ?? 0);

//             // ===== preload semua discount yang dipakai =====
//             $needIds = [];
//             if (!empty($data['discount_id'])) $needIds[] = (int)$data['discount_id'];
//             foreach ($data['items'] as $it) {
//                 if (!empty($it['discount_id'])) $needIds[] = (int)$it['discount_id'];
//             }
//             $needIds = array_values(array_unique($needIds));

//             $discountMap = Discount::query()
//                 ->whereIn('id', $needIds)
//                 ->where('active', 1)
//                 ->get()
//                 ->keyBy('id');

//             // ===== hitung per item =====
//             $subtotal = 0.0;
//             $itemDiscountTotal = 0.0;
//             $rows = [];

//             foreach ($data['items'] as $it) {
//                 $qty       = (float)$it['qty'];
//                 $unitPrice = (float)$it['unit_price'];
//                 $lineSub   = $qty * $unitPrice;

//                 $subtotal += $lineSub;

//                 $d = null;
//                 $dNominal = 0.0;

//                 if (!empty($it['discount_id'])) {
//                     $d = $discountMap[(int)$it['discount_id']] ?? null;

//                     // harus scope ITEM
//                     if ($d && $d->scope !== 'ITEM') {
//                         throw ValidationException::withMessages([
//                             'items' => ["Discount item tidak valid (scope bukan ITEM)."],
//                         ]);
//                     }

//                     if ($d) {
//                         // min_subtotal untuk ITEM: base-nya baris item
//                         if ($d->min_subtotal !== null && $lineSub < (float)$d->min_subtotal) {
//                             throw ValidationException::withMessages([
//                                 'items' => ["Diskon item '{$d->name}' minimal pembelian belum terpenuhi."],
//                             ]);
//                         }

//                         $dNominal = $this->calcDiscountAmount($d, $lineSub);
//                     }
//                 }

//                 // clamp agar tidak melebihi lineSub
//                 $dNominal = max(0.0, min($dNominal, $lineSub));
//                 $itemDiscountTotal += $dNominal;

//                 $netLine = $lineSub - $dNominal;
//                 $netUnit = $netLine / $qty;

//                 $rows[] = [
//                     'product_id'       => (int)$it['product_id'],
//                     'qty'              => $qty,
//                     'unit_price'       => $unitPrice,

//                     'discount_id'      => $d?->id,
//                     'discount_name'    => $d?->name,
//                     'discount_kind'    => $d?->kind,
//                     'discount_value'   => $d?->value,
//                     'discount_nominal' => $dNominal,

//                     'net_unit_price'   => $netUnit,
//                     'line_total'       => $netLine,
//                 ];
//             }

//             // ===== hitung diskon global =====
//             $baseForGlobal = max(0.0, $subtotal - $itemDiscountTotal);

//             $global = null;
//             $globalNominal = 0.0;

//             if (!empty($data['discount_id'])) {
//                 $global = $discountMap[(int)$data['discount_id']] ?? null;

//                 // harus scope GLOBAL
//                 if ($global && $global->scope !== 'GLOBAL') {
//                     throw ValidationException::withMessages([
//                         'discount_id' => ["Discount global tidak valid (scope bukan GLOBAL)."],
//                     ]);
//                 }

//                 if ($global) {
//                     if ($global->min_subtotal !== null && $baseForGlobal < (float)$global->min_subtotal) {
//                         throw ValidationException::withMessages([
//                             'discount_id' => ["Diskon '{$global->name}' minimal belanja belum terpenuhi."],
//                         ]);
//                     }

//                     $globalNominal = $this->calcDiscountAmount($global, $baseForGlobal);
//                 }
//             }

//             $globalNominal = max(0.0, min($globalNominal, $baseForGlobal));

//             // ===== total akhir =====
//             $totalAfterDiscount = $baseForGlobal - $globalNominal;
//             $total = max(0.0, $totalAfterDiscount + $service + $tax);

//             // ===== simpan sale =====
//             $sale = Sale::create([
//                 'cashier_id'        => $data['cashier_id'],
//                 'store_location_id' => $data['store_location_id'],
//                 'customer_name'     => $data['customer_name'] ?? 'General',

//                 'subtotal'          => $subtotal,

//                 // NOMINAL diskon global (untuk struk)
//                 'discount'          => $globalNominal,

//                 // snapshot + id master
//                 'discount_id'       => $global?->id,
//                 'discount_name'     => $global?->name,
//                 'discount_kind'     => $global?->kind,
//                 'discount_value'    => $global?->value,

//                 'service_charge'    => $service,
//                 'tax'               => $tax,
//                 'total'             => $total,

//                 'paid'              => 0,
//                 'change'            => 0,
//                 'status'            => 'draft', // sesuaikan flow kamu (draft/completed)
//             ]);

//             // ===== simpan items =====
//             foreach ($rows as $row) {
//                 $row['sale_id'] = $sale->id;
//                 SaleItem::create($row);
//             }

//             return response()->json([
//                 'sale_id'              => $sale->id,
//                 'subtotal'             => $subtotal,
//                 'item_discount_total'  => $itemDiscountTotal,
//                 'global_discount'      => $globalNominal,
//                 'service_charge'       => $service,
//                 'tax'                  => $tax,
//                 'total'                => $total,
//             ], 201);
//         });
//     }

//     private function calcDiscountAmount(Discount $d, float $base): float
//     {
//         $amount = 0.0;

//         if ($d->kind === 'PERCENT') {
//             $pct = (float)$d->value; // 10 = 10%
//             $amount = $base * ($pct / 100.0);

//             if ($d->max_amount !== null) {
//                 $amount = min($amount, (float)$d->max_amount);
//             }
//         } else { // FIXED
//             $amount = (float)$d->value;
//         }

//         return max(0.0, $amount);
//     }
// }
