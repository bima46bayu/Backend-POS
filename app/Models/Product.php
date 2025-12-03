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

    protected $fillable = [
        'category_id',
        'sub_category_id',
        'sku',
        'name',
        'description',
        'price',
        'stock',
        'image_url',
        'store_location_id',
        'created_by',
        'unit_id',     // ← penting untuk relasi unit
        'unit_name',   // kalau kamu pakai kolom ini di DB
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
}
