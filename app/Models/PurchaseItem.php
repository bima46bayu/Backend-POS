<?php

// app/Models/PurchaseItem.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\Purchase;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PurchaseItem extends Model {
  protected $fillable = ['purchase_id','product_id','qty_order','qty_received','unit_price','discount','tax','line_total'];
  protected $attributes = ['qty_received'=>0,'discount'=>0,'tax'=>0];
  public function purchase(){ return $this->belongsTo(Purchase::class); }
  public function product(){ return $this->belongsTo(Product::class); }
}
