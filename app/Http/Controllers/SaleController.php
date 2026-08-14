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
use App\Models\Discount;
use App\Models\AdditionalCharge;
use App\Models\Member;
use App\Models\ProductOptionValue;
use App\Models\RegisterSession;

use Illuminate\Support\Facades\Schema;

use App\Services\InventoryService;
use App\Services\LoyaltyService;
use App\Services\RecipeService;

class SaleController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesStoreAccess;

    public function index(Request $r)
    {
        $user = $r->user();

        // List screens (web History, mobile Riwayat) do not need line items;
        // skipping them avoids PHP memory exhaustion on large pages.
        // Always constrain relation columns — full Product rows (description, etc.)
        // routinely truncate JSON under the shared host memory limit.
        $withoutItems = $r->boolean('without_items');
        $relations = [
            'cashier:id,name',
            'storeLocation:id,code,name,address,phone,logo_url',
            'payments:id,sale_id,method,amount,reference',
        ];
        if (! $withoutItems) {
            $relations = array_merge([
                'items:id,sale_id,product_id,qty,unit_price,discount_nominal,net_unit_price,line_total,discount_id,discount_name,discount_kind,discount_value,options,options_price',
                'items.product:id,name,sku,category_id,sub_category_id,price',
            ], $relations);
        }

        $q = Sale::with($relations)->latest('id');

        $this->applySaleStoreScope($q, $r);

        /* ==============================
        * 🔎 FILTER LAIN
        * ============================== */

        if ($r->filled('code')) {
            $q->where('code', 'like', '%' . $r->code . '%');
        }

        if ($r->filled('cashier_id')) {
            $q->where('cashier_id', $r->cashier_id);
        }

        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }

        if ($r->filled('from')) {
            $q->whereDate('created_at', '>=', $r->from);
        }

        if ($r->filled('to')) {
            $q->whereDate('created_at', '<=', $r->to);
        }

        if ($r->boolean('only_discount')) {
            $q->where(function ($qq) {
                $qq->where('discount', '>', 0)
                ->orWhereHas('items', function ($qi) {
                    $qi->where('discount_nominal', '>', 0);
                });
            });
        }

        /* ==============================
        * 📄 PAGINATION
        * ============================== */

        // Hard caps: with line items+product, keep pages small so JSON serialization
        // stays under the host memory limit. Clients that need more must paginate.
        $perPage = (int) ($r->per_page ?? 10);
        $maxPerPage = $withoutItems ? 200 : 50;
        $perPage = max(1, min($maxPerPage, $perPage));

        return response()->json(
            $q->paginate($perPage)
        );
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

        $this->authorizeStoreAccess($user, (int) $storeId);

        /* =====================================================
        * OFFLINE SYNC — IDEMPOTENCY
        * Sale yang dibuat offline dikirim ulang sampai sukses. Kalau uuid-nya
        * sudah pernah masuk, balikin sale yang ada (JANGAN buat dobel).
        * ===================================================== */
        $clientUuid = $request->input('client_uuid');
        $isOffline  = $request->boolean('offline');

        if ($clientUuid && Schema::hasColumn('sales', 'client_uuid')) {
            $existing = Sale::where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return response()->json(
                    $existing->load(['items.product', 'payments', 'cashier', 'storeLocation']),
                    200
                );
            }
        }

        /* =====================================================
        * REGISTER
        * Sale offline bisa nyampe SETELAH register ditutup (kasir tutup shift
        * sambil masih ada antrian sync). Selama sale-nya memang dibuat waktu
        * register itu masih kebuka, tetap kita terima.
        * ===================================================== */
        $openRegister = RegisterSession::where('cashier_id', $user->id)
            ->where('store_location_id', $storeId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if (!$openRegister && $isOffline && $request->filled('register_session_id')) {
            $openRegister = RegisterSession::where('id', $request->input('register_session_id'))
                ->where('cashier_id', $user->id)
                ->where('store_location_id', $storeId)
                ->first();
        }

        if (!$openRegister) {
            abort(422, 'Register belum dibuka. Silakan buka register terlebih dahulu.');
        }

        /* =====================================================
        * MEMBER (customer database)
        * Member dimiliki grup toko induk, jadi kartu dari cabang lain dalam
        * satu induk tetap valid — tapi member dari grup LAIN ditolak.
        * ===================================================== */
        $memberId = null;
        if ($request->filled('member_id')) {
            $member = Member::find($request->input('member_id'));

            if (! $member) {
                abort(422, 'Member tidak ditemukan.');
            }
            if (! $member->is_active) {
                abort(422, "Member {$member->code} sudah tidak aktif.");
            }
            if ((int) $member->store_location_id !== Member::ownerStoreId((int) $storeId)) {
                abort(422, 'Member ini bukan milik grup toko yang sama.');
            }

            $memberId = (int) $member->id;
        }

        return DB::transaction(function () use ($request, $user, $storeId, $clientUuid, $isOffline, $memberId) {

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

            // eager load option groups (hindari N+1 saat validasi opsi)
            $products->load('optionGroups');

            $recipeService = app(RecipeService::class);
            $recipesByProduct = $recipeService->loadActiveForProducts($productIds, (int) $storeId);

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
            * PRELOAD OPTION VALUES (sugar level, ice level, dll)
            * ===================================================== */
            $optionValueIds = [];
            foreach ($itemsInput as $it) {
                foreach ((array) ($it['option_value_ids'] ?? []) as $vid) {
                    $optionValueIds[] = (int) $vid;
                }
            }

            $optionValueMap = $optionValueIds === []
                ? collect()
                : ProductOptionValue::with(['group.ingredient.unit', 'qtyDeltaUnit'])
                    ->whereIn('id', array_unique($optionValueIds))
                    ->get()
                    ->keyBy('id');

            /* =====================================================
            * HITUNG ITEMS (ITEM DISCOUNT)
            * ===================================================== */
            $subtotal = 0.0;
            $saleItemsPayload = [];

            // Offline: uang sudah diterima customer, jadi kekurangan stok dicatat
            // (bukan bikin sale gagal). Manager review lewat flag needs_review.
            $stockShortfall = [];

            // Ingredient needs are built per cart line so two Dawets with different
            // ice options don't cancel each other out when summed by product_id.
            $ingredientNeeds = [];

            foreach ($itemsInput as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (!$product) abort(422, "Product {$row['product_id']} tidak ditemukan");

                $qty = (int)($row['qty'] ?? 0);
                if ($qty < 1) abort(422, 'Qty minimal 1');

                if ($product->store_location_id !== null && (int) $product->store_location_id !== (int) $storeId) {
                    abort(422, "Produk {$product->name} tidak tersedia di cabang ini.");
                }

                if ($recipesByProduct->has($product->id)) {
                    // Recipe stock is validated after options are resolved (below).
                } elseif ($product->isStockTracked()) {
                    $available = InventoryService::sumQtyRemaining($product->id, (int) $storeId);
                    if ($available < $qty) {
                        if (! $isOffline) {
                            abort(422, "Stok {$product->name} tidak cukup (tersisa {$available})");
                        }

                        // Offline sale: terima, tapi catat minusnya.
                        $stockShortfall[] = [
                            'product_id'   => (int) $product->id,
                            'product_name' => $product->name,
                            'qty_sold'     => $qty,
                            'qty_available' => (float) $available,
                            'shortfall'    => round($qty - (float) $available, 4),
                            'kind'         => 'PRODUCT',
                        ];
                    }
                }

                /* ---------- ITEM OPTIONS (sugar level, ice level, dll) ---------- */
                $optionsSnapshot = [];
                $optionsPrice    = 0.0;
                $selectedOptionValues = [];

                $rawOptionIds = array_values(array_unique(array_map(
                    fn ($v) => (int) $v,
                    (array) ($row['option_value_ids'] ?? [])
                )));

                if ($rawOptionIds !== []) {
                    // group yang boleh dipakai produk ini
                    $allowedGroupIds = $product->optionGroups
                        ->pluck('id')
                        ->map(fn ($v) => (int) $v)
                        ->all();

                    $seenSingleGroups = [];

                    foreach ($rawOptionIds as $vid) {
                        $val = $optionValueMap[$vid] ?? null;
                        if (! $val || ! $val->group) {
                            abort(422, "Opsi item tidak valid.");
                        }

                        $group = $val->group;

                        if (! in_array((int) $group->id, $allowedGroupIds, true)) {
                            abort(422, "Opsi '{$group->name}' tidak tersedia untuk produk {$product->name}.");
                        }

                        if (! $val->is_active || ! $group->is_active) {
                            abort(422, "Opsi '{$val->name}' sudah tidak aktif.");
                        }

                        if (! $group->isMulti()) {
                            if (isset($seenSingleGroups[$group->id])) {
                                abort(422, "Opsi '{$group->name}' hanya boleh dipilih satu.");
                            }
                            $seenSingleGroups[$group->id] = true;
                        }

                        $delta = round((float) $val->price_delta, 2);
                        $optionsPrice += $delta;
                        $selectedOptionValues[] = $val;

                        $optionsSnapshot[] = [
                            'group_id'               => (int) $group->id,
                            'group'                  => $group->name,
                            'value_id'               => (int) $val->id,
                            'name'                   => $val->name,
                            'price_delta'            => $delta,
                            'qty_delta'              => (float) ($val->qty_delta ?? 0),
                            'qty_delta_unit_id'      => $val->qty_delta_unit_id
                                ? (int) $val->qty_delta_unit_id
                                : null,
                            'qty_delta_unit'         => $val->qtyDeltaUnit?->name,
                            'ingredient_product_id'  => $group->ingredient_product_id
                                ? (int) $group->ingredient_product_id
                                : null,
                        ];
                    }
                }

                // wajib: semua group required milik produk harus terpilih
                $requiredGroups = $product->optionGroups
                    ->filter(fn ($g) => $g->is_active && $g->is_required);

                foreach ($requiredGroups as $rg) {
                    $picked = collect($optionsSnapshot)
                        ->firstWhere('group_id', (int) $rg->id);

                    if (! $picked) {
                        abort(422, "Opsi '{$rg->name}' wajib dipilih untuk produk {$product->name}.");
                    }
                }

                $optionsPrice = round(max(0.0, $optionsPrice), 2);

                $qtyDeltas = $recipeService->qtyDeltasFromOptions($selectedOptionValues);
                $recipe = $recipesByProduct->get($product->id);

                if ($recipe || $qtyDeltas !== []) {
                    $lineNeeds = $recipeService->ingredientNeedsForSaleLine(
                        $recipe,
                        (float) $qty,
                        $qtyDeltas
                    );
                    foreach ($lineNeeds as $ingId => $needQty) {
                        $ingredientNeeds[$ingId] = ($ingredientNeeds[$ingId] ?? 0.0) + $needQty;
                    }
                }

                $basePrice = (float)($row['unit_price'] ?? $product->price);
                $unitPrice = round($basePrice + $optionsPrice, 2);
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

                    // 🔥 SNAPSHOT OPSI ITEM (cast 'array' di model yang meng-encode)
                    'options'          => $optionsSnapshot === [] ? null : $optionsSnapshot,
                    'options_price'    => $optionsPrice,

                    // Used only while consuming stock in this request (not a DB column).
                    '_qty_deltas'      => $qtyDeltas,

                    // 🔥 SNAPSHOT DISKON ITEM
                    'discount_id'      => $disc?->id,
                    'discount_name'    => $disc?->name,
                    'discount_kind'    => $disc?->kind,
                    'discount_value'   => $disc?->value,
                    'discount_nominal' => round($discNominal, 2),

                    'net_unit_price'   => round($netUnit, 2),
                    'line_total'       => round($netLine, 2),
                ];
            }

            if ($isOffline) {
                // Jangan tolak — uang sudah masuk. Catat kekurangannya.
                $stockShortfall = array_merge(
                    $stockShortfall,
                    $recipeService->collectIngredientShortfall($ingredientNeeds, (int) $storeId)
                );
            } else {
                $recipeService->validateIngredientStock($ingredientNeeds, (int) $storeId);
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
                ->get()
                ->sortBy(fn ($c) => match ($c->type) {
                    'SERVICE' => 0,
                    'PB1' => 1,
                    default => 9,
                });

            $additionalSnapshot = [];
            $additionalTotal = 0.0;
            $serviceAmount = 0.0;

            foreach ($additionalCharges as $c) {
                $base = $c->type === 'PB1'
                    ? $grandTotal + $serviceAmount
                    : $grandTotal;

                if ($c->calc_type === 'PERCENT') {
                    $amount = $base * ($c->value / 100);
                } else {
                    $amount = $c->value;
                }

                $amount = round($amount, 2);
                $additionalTotal += $amount;

                if ($c->type === 'SERVICE') {
                    $serviceAmount = $amount;
                }

                $additionalSnapshot[] = [
                    'type'      => $c->type,
                    'calc_type' => $c->calc_type,
                    'value'     => (float) $c->value,
                    'base'      => round($base, 2),
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
            /*
             | Offline sale keeps the time the customer actually paid, so it lands
             | in the right day/report/register instead of the sync time.
             */
            $soldAt = $isOffline && $request->filled('offline_created_at')
                ? \Carbon\Carbon::parse($request->input('offline_created_at'))
                : now();

            // Guard against a wrong device clock producing future-dated sales.
            if ($soldAt->greaterThan(now())) {
                $soldAt = now();
            }

            $seq = Sale::whereDate('created_at', $soldAt->toDateString())
                ->lockForUpdate()
                ->count() + 1;

            $code = 'POS-' . $soldAt->format('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            // Sequence bisa tabrakan kalau ada sale offline nyusul di tanggal yang
            // sama. Kode wajib unique, jadi geser sampai bebas.
            while (Sale::where('code', $code)->exists()) {
                $seq++;
                $code = 'POS-' . $soldAt->format('Ymd') . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
            }

            $sale = Sale::create([
                'code'              => $code,
                'client_uuid'       => $clientUuid,
                'cashier_id'        => $user->id,
                'store_location_id' => $storeId,
                'customer_name'     => $request->customer_name ?? 'General',
                'member_id'         => $memberId,

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

                // offline sync metadata
                'is_offline'         => $isOffline,
                'offline_created_at' => $isOffline ? $soldAt : null,
                'synced_at'          => $isOffline ? now() : null,
                'stock_shortfall'    => $stockShortfall === [] ? null : $stockShortfall,
                'needs_review'       => $stockShortfall !== [],
            ]);

            /*
             | Backdate ke waktu transaksi asli supaya laporan harian & summary
             | register menaruhnya di shift yang benar. created_at bukan fillable,
             | jadi harus di-set terpisah dari mass assignment di atas.
             */
            if ($isOffline && ! $soldAt->equalTo($sale->created_at)) {
                $sale->created_at = $soldAt;
                $sale->saveQuietly();
            }

            /* =====================================================
            * SAVE ITEMS + FIFO + STOCK LEDGER (CARA KEMARIN)
            * ===================================================== */
            $inv = app(InventoryService::class);

            foreach ($saleItemsPayload as $payload) {
                $qtyDeltas = $payload['_qty_deltas'] ?? [];
                unset($payload['_qty_deltas']);

                $payload['sale_id'] = $sale->id;
                $item = SaleItem::create($payload);

                $product = $products[$item->product_id] ?? null;
                $recipe = $recipesByProduct->get($item->product_id);

                if ($recipe || $qtyDeltas !== []) {
                    $lineNeeds = $recipeService->ingredientNeedsForSaleLine(
                        $recipe,
                        (float) $item->qty,
                        $qtyDeltas
                    );

                    foreach ($lineNeeds as $ingredientId => $ingredientQty) {
                        $this->consumeSaleInventory(
                            $inv,
                            $sale,
                            $storeId,
                            $user,
                            (int) $ingredientId,
                            (float) $ingredientQty,
                            $item->id,
                            0.0,
                            $isOffline
                        );
                    }
                } elseif ($product && $product->isStockTracked()) {
                    $this->consumeSaleInventory(
                        $inv,
                        $sale,
                        $storeId,
                        $user,
                        (int) $item->product_id,
                        (float) $item->qty,
                        $item->id,
                        (float) $item->net_unit_price,
                        $isOffline
                    );
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

            /* =====================================================
            * LOYALTY POINTS
            * Dihitung dari total final yang sudah tersimpan (bukan kiriman
            * client). Idempotent lewat unique (sale_id, type) di ledger, jadi
            * sale offline yang di-sync ulang tidak dobel poin.
            * ===================================================== */
            LoyaltyService::awardForSale($sale, $user->id);

            return response()->json(
                $sale->load(['items.product', 'payments', 'cashier', 'storeLocation', 'member']),
                201
            );
        });
    }

    public function void(Sale $sale)
    {
        if ($sale->status === 'void') {
            return response()->json(['message' => 'Sale already void'], 422);
        }

        $user = auth()->user();
        $this->authorizeStoreAccess($user, (int) $sale->store_location_id);

        // Kasir must provide the parent-store void security code (manager override).
        if ($user && method_exists($user, 'isKasir') && $user->isKasir()) {
            $code = request()->input('security_code');
            if (!\App\Models\AppSetting::verifyVoidSecurityCode(
                is_string($code) ? $code : null,
                (int) $sale->store_location_id
            )) {
                return response()->json([
                    'message' => 'Kode keamanan salah atau belum diisi.',
                    'errors' => [
                        'security_code' => ['Kode keamanan salah.'],
                    ],
                ], 422);
            }
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
            $productIdsToSync = $cons->pluck('product_id')->unique()->all();

            DB::table('inventory_consumptions')
                ->where('sale_id', $sale->id)
                ->whereNull('reversed_at')
                ->update(['reversed_at' => now(), 'updated_at' => now()]);

            foreach ($productIdsToSync as $pid) {
                InventoryService::syncLegacyProductStock(
                    (int) $pid,
                    (int) $sale->store_location_id
                );
            }

            // 5) Status sale
            $sale->update(['status' => 'void']);

            /*
             | 6) Tarik kembali poin member.
             | Membalik jumlah yang tercatat di ledger, BUKAN hitung ulang dari
             | rate sekarang — kalau rate-nya sudah diubah admin, hitung ulang
             | akan salah.
             */
            $revoked = LoyaltyService::revokeForSale($sale, auth()->id());

            return response()->json([
                'message'        => 'Sale voided',
                'points_revoked' => $revoked,
                'sale'           => $sale->fresh(['items.product', 'payments', 'cashier', 'member'])
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

    private function consumeSaleInventory(
        InventoryService $inv,
        Sale $sale,
        int $storeId,
        $user,
        int $productId,
        float $qty,
        int $saleItemId,
        float $saleUnitPrice,
        bool $allowShortfall = false
    ): void {
        if ($qty <= 0) {
            return;
        }

        $inv->consumeFIFOWithPricing([
            'product_id'        => $productId,
            'store_location_id' => $storeId,
            'qty'               => $qty,
            'sale_id'           => $sale->id,
            'sale_item_id'      => $saleItemId,
            'sale_unit_price'   => $saleUnitPrice,
            'user_id'           => $user->id,
            'allow_shortfall'   => $allowShortfall,
        ]);

        $consQuery = DB::table('inventory_consumptions')
            ->where('product_id', $productId);

        if (Schema::hasColumn('inventory_consumptions', 'sale_item_id')) {
            $consQuery->where('sale_item_id', $saleItemId);
        } else {
            $consQuery->where('sale_id', $sale->id);
        }

        $consRows = $consQuery
            ->orderBy('id')
            ->get(['layer_id', 'qty', 'unit_cost']);

        if (Schema::hasTable('stock_ledger')) {
            foreach ($consRows as $c) {
                DB::table('stock_ledger')->insert([
                    'product_id'        => $productId,
                    'store_location_id' => $storeId,
                    'layer_id'          => $c->layer_id,
                    'user_id'           => $user->id ?? null,
                    'ref_type'          => 'SALE',
                    'ref_id'            => $sale->id,
                    'direction'         => -1,
                    'qty'               => (float) $c->qty,
                    'unit_cost'         => (float) $c->unit_cost,
                    'unit_price'        => $saleUnitPrice > 0 ? $saleUnitPrice : null,
                    'subtotal_cost'     => (float) $c->qty * (float) $c->unit_cost,
                    'note'              => "sale #{$sale->code}",
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        }

        InventoryService::syncLegacyProductStock($productId, $storeId);
    }
}
