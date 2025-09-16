<?php

// app/Models/GoodsReceipt.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\GoodsReceiptItem;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoodsReceipt extends Model {
  protected $fillable = ['gr_number','purchase_id','received_by','received_date','status','notes'];
  public function items(){ return $this->hasMany(GoodsReceiptItem::class); }
  public function purchase(){ return $this->belongsTo(Purchase::class); }

  public static function nextNumber(): string {
    $prefix = 'GR-'.now()->format('Ym').'-';
    $last = static::where('gr_number','like',$prefix.'%')->max('gr_number');
    $seq  = $last ? ((int)substr($last,-4))+1 : 1;
    return $prefix.sprintf('%04d',$seq);
  }
}

