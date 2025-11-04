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
        'category_id','sku','name','description','price','stock','sub_category_id','image_url',
        'store_location_id','created_by',   // ⬅️ penting untuk opsi A
    ];

    // ===== Relasi =====
    public function category()      { return $this->belongsTo(Category::class); }
    public function storeLocation() { return $this->belongsTo(\App\Models\StoreLocation::class, 'store_location_id'); }
    public function stockLogs()     { return $this->hasMany(StockLog::class); }
    // public function layers()       { return $this->hasMany(\App\Models\InventoryLayer::class); }
    // public function consumptions() { return $this->hasMany(\App\Models\InventoryConsumption::class); }

    /**
     * Filter katalog untuk store tertentu.
     * $includeGlobal=true → produk global (NULL) juga ikut.
     */
    public function scopeForStore($query, ?int $storeId, bool $includeGlobal = true)
    {
        if (!$storeId) return $query;
        return $query->where(function ($w) use ($storeId, $includeGlobal) {
            if ($includeGlobal) $w->whereNull('store_location_id');   // global
            $w->orWhere('store_location_id', $storeId);               // milik toko
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
            if (method_exists($product, 'isForceDeleting') && !$product->isForceDeleting()) {
                return; // soft delete → jangan hapus layer/file
            }

            DB::transaction(function () use ($product) {
                if (Schema::hasTable('inventory_layers')) {
                    DB::table('inventory_layers')->where('product_id', $product->id)->delete();
                }

                if (!empty($product->image_url)) {
                    try {
                        $url = $product->image_url;

                        if (str_starts_with($url, '/storage/')) {
                            $relative = Str::after($url, '/storage/');
                            if ($relative && $relative !== $url) {
                                Storage::disk('public')->delete($relative);
                            }
                        }

                        if (str_contains($url, '/uploads/products/')) {
                            $clean = Str::replaceFirst('/public/', '/', $url);
                            $full  = public_path(ltrim($clean, '/'));
                            if (File::exists($full)) {
                                @unlink($full);
                            }
                        }
                    } catch (\Throwable $e) {}
                }
            });
        });
    }
}
