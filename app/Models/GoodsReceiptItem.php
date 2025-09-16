<?php

// app/Models/GoodsReceiptItem.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\GoodsReceipt;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoodsReceiptItem extends Model {
  protected $fillable = ['goods_receipt_id','purchase_item_id','qty_received','condition_notes'];
  public function goodsReceipt(){ return $this->belongsTo(GoodsReceipt::class); }
  public function purchaseItem(){ return $this->belongsTo(PurchaseItem::class); }
}

