<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class Product extends Model
{
    use HasFactory, SoftDeletes;
    const INVENTORY_TYPE_STOCK     = 'stock';
    const INVENTORY_TYPE_NON_STOCK = 'non_stock';

    protected $fillable = [
        'category_id',
        'sub_category_id',
        'sku',
        'name',
        'description',
        'price',
        'cost_price',
        'pack_size',
        'pack_label',
        'stock',
        'image_url',
        'store_location_id',
        'created_by',
        'unit_id',     // ← penting untuk relasi unit
        'unit_name',   // kalau kamu pakai kolom ini di DB
        'inventory_type',
    ];

    // ===== Relasi =====
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    public function storeLocation()
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    public function stockLogs()
    {
        return $this->hasMany(StockLog::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function optionGroups()
    {
        return $this->belongsToMany(
            ProductOptionGroup::class,
            'product_product_option_group',
            'product_id',
            'product_option_group_id'
        );
    }

    /**
     * Filter katalog untuk store tertentu.
     * $includeGlobal=true → produk global (NULL) juga ikut.
     */
    public function scopeForStore($query, ?int $storeId, bool $includeGlobal = true)
    {
        if (!$storeId) {
            return $query;
        }

        return $query->where(function ($w) use ($storeId, $includeGlobal) {
            if ($includeGlobal) {
                $w->whereNull('store_location_id');   // global
            }
            $w->orWhere('store_location_id', $storeId); // milik toko
        });
    }

    /**
     * Cascade delete di level aplikasi:
     * - Soft delete: TIDAK menghapus layer (histori aman).
     * - Force delete: HAPUS layer + file image (karena produk benar-benar dihapus).
     */
    protected static function booted()
    {
        static::deleting(function (Product $product) {
            // kalau pakai SoftDeletes, cek dulu
            if (method_exists($product, 'isForceDeleting') && !$product->isForceDeleting()) {
                return; // soft delete → jangan hapus layer/file
            }

            DB::transaction(function () use ($product) {
                if (Schema::hasTable('inventory_layers')) {
                    DB::table('inventory_layers')
                        ->where('product_id', $product->id)
                        ->delete();
                }

                if (!empty($product->image_url)) {
                    try {
                        $url = $product->image_url;

                        // file di storage public
                        if (str_starts_with($url, '/storage/')) {
                            $relative = Str::after($url, '/storage/');
                            if ($relative && $relative !== $url) {
                                Storage::disk('public')->delete($relative);
                            }
                        }

                        // file di public/uploads/products
                        if (str_contains($url, '/uploads/products/')) {
                            $clean = Str::replaceFirst('/public/', '/', $url);
                            $full  = public_path(ltrim($clean, '/'));
                            if (File::exists($full)) {
                                @unlink($full);
                            }
                        }
                    } catch (\Throwable $e) {
                        // swallow error supaya delete tetap jalan
                    }
                }
            });
        });
    }

    public function isStockTracked(): bool
    {
        return $this->inventory_type === self::INVENTORY_TYPE_STOCK;
    }

    /**
     * Cost basis for inventory valuation.
     *
     * Never falls back to `price`: `price` is what we SELL for, and using it as
     * a cost makes COGS equal revenue. An unknown cost is 0.0 (visibly wrong,
     * so it gets noticed) instead of plausibly wrong.
     */
    public function costBasis(): float
    {
        return (float) ($this->cost_price ?? 0);
    }

    /**
     * How many small "contents" units fit inside ONE stock unit — e.g. a
     * product stocked in Pack with pack_size 100 holds 100 straws per pack.
     *
     * Stock, cost and purchasing all stay in the stock unit (Pack). This value
     * exists so a recipe can consume a fraction of a pack: 1 Batang = 1/100
     * Pack. It is deliberately NOT a cost divisor — cost_price is already per
     * stock unit, and dividing it again is what produced the 5000 -> 50 bug.
     *
     * A pack_size of 1 is meaningless (a pack of one = no pack), so it is
     * treated as "no pack" to keep callers from dividing by a no-op.
     */
    public function packSize(): ?float
    {
        $size = $this->pack_size !== null ? (float) $this->pack_size : null;

        if ($size === null || $size <= 1.0) {
            return null;
        }

        return $size;
    }

    public function isPacked(): bool
    {
        return $this->packSize() !== null;
    }

    /**
     * Convert a contents qty into stock units, for recipe consumption:
     * 2 Batang of a 100-per-Pack product → 0.02 Pack.
     *
     * Falls through unchanged when the product has no pack_size, so callers can
     * apply it unconditionally.
     */
    public function contentsToStockUnits(float $contentsQty): float
    {
        $size = $this->packSize();

        return $size === null ? $contentsQty : $contentsQty / $size;
    }

    /** Inverse of contentsToStockUnits(): 0.02 Pack → 2 Batang. For display. */
    public function stockUnitsToContents(float $stockQty): float
    {
        $size = $this->packSize();

        return $size === null ? $stockQty : $stockQty * $size;
    }

    /**
     * Cost of one contents unit (Rp 5.000 per Pack of 100 → Rp 50 per Batang).
     * Derived for display/reporting only; the stored cost stays per stock unit.
     */
    public function costPerContentsUnit(): float
    {
        return $this->contentsToStockUnits(1.0) * $this->costBasis();
    }
}
