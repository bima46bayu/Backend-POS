<?php

namespace App\Models;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\PurchaseItem;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;

class Purchase extends Model {
  protected $fillable = ['purchase_number','supplier_id','user_id','order_date','expected_date',
    'subtotal','tax_total','other_cost','grand_total','status','approved_by','approved_at','notes'];
  public function items(){ return $this->hasMany(PurchaseItem::class); }
  public function supplier(){ return $this->belongsTo(Supplier::class); }
  public function user(){ return $this->belongsTo(User::class); }
  public function approver(){ return $this->belongsTo(User::class,'approved_by'); }

  public static function nextNumber(): string {
    $prefix = 'PO-'.now()->format('Ym').'-';
    $last = static::where('purchase_number','like',$prefix.'%')->max('purchase_number');
    $seq  = $last ? ((int)substr($last,-4))+1 : 1;
    return $prefix.sprintf('%04d',$seq);
  }
}


