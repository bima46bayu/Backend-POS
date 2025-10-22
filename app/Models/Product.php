<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;        // ← aktifkan soft deletes
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id','sku','name','description','price','stock','sub_category_id','image_url'
    ];

    // ===== Relasi (opsional) =====
    public function category()     { return $this->belongsTo(Category::class); }
    public function stockLogs()    { return $this->hasMany(StockLog::class); }
    // Tambahkan relasi ini jika kamu punya modelnya:
    // public function layers()       { return $this->hasMany(\App\Models\InventoryLayer::class); }
    // public function consumptions() { return $this->hasMany(\App\Models\InventoryConsumption::class); }

    /**
     * Cascade delete di level aplikasi:
     * - Soft delete: TIDAK menghapus layer (histori aman).
     * - Force delete: HAPUS layer + file image (karena produk benar-benar dihapus).
     */
    protected static function booted()
    {
        static::deleting(function (Product $product) {
            // Saat soft delete (bukan forceDelete) → jangan hapus layer/file.
            if (method_exists($product, 'isForceDeleting') && !$product->isForceDeleting()) {
                return;
            }

            // Hanya saat force delete:
            DB::transaction(function () use ($product) {
                // Hapus inventory layers milik produk (jika tabel ada)
                if (Schema::hasTable('inventory_layers')) {
                    DB::table('inventory_layers')
                        ->where('product_id', $product->id)
                        ->delete();
                }

                // Hapus file image dari 2 kemungkinan lokasi
                if (!empty($product->image_url)) {
                    try {
                        $url = $product->image_url;

                        // /storage/products/xxx.jpg (disk 'public')
                        if (str_starts_with($url, '/storage/')) {
                            $relative = Str::after($url, '/storage/'); // "products/xxx.jpg"
                            if ($relative && $relative !== $url) {
                                Storage::disk('public')->delete($relative);
                            }
                        }

                        // /uploads/products/... atau /public/uploads/products/...
                        if (str_contains($url, '/uploads/products/')) {
                            $clean = Str::replaceFirst('/public/', '/', $url);
                            $full  = public_path(ltrim($clean, '/'));
                            if (File::exists($full)) {
                                @unlink($full);
                            }
                        }
                    } catch (\Throwable $e) {
                        // optional: \Log::warning('Delete product image failed: '.$e->getMessage());
                    }
                }
            });
        });
    }
}
