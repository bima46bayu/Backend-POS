<?php

// app/Models/GoodsReceipt.php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Models\GoodsReceiptItem;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoodsReceipt extends Model {
  protected $fillable = [
    'gr_number','purchase_id','received_by','received_date','status','notes',
    'reversed_at','review_flagged_at','review_flagged_by','review_reason',
  ];

  protected $casts = [
    'received_date' => 'date',
    'reversed_at' => 'datetime',
    'review_flagged_at' => 'datetime',
  ];

  public function toArray()
  {
    $arr = parent::toArray();
    if ($this->reversed_at) {
      $arr['status'] = 'reversed';
    }
    $arr['review_flagged'] = $this->review_flagged_at !== null;
    return $arr;
  }
  public function items(){ return $this->hasMany(GoodsReceiptItem::class); }
  public function purchase(){ return $this->belongsTo(Purchase::class); }
  public function reviewFlaggedBy(){ return $this->belongsTo(User::class, 'review_flagged_by'); }

  public static function nextNumber(): string {
    $prefix = 'GR-'.now()->format('Ym').'-';
    $last = static::where('gr_number','like',$prefix.'%')->max('gr_number');
    $seq  = $last ? ((int)substr($last,-4))+1 : 1;
    return $prefix.sprintf('%04d',$seq);
  }
}

