<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id','sku','name','description','price','stock','image_url'
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function stockLogs() {
        return $this->hasMany(StockLog::class);
    }
}
