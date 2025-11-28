<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_number',
        'supplier_id',
        'user_id',
        'store_location_id', // ⬅️ penting: tambahkan ini
        'order_date',
        'expected_date',
        'subtotal',
        'tax_total',
        'other_cost',
        'grand_total',
        'status',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'expected_date' => 'date',
        'approved_at'   => 'datetime',
        'subtotal'      => 'float',
        'tax_total'     => 'float',
        'other_cost'    => 'float',
        'grand_total'   => 'float',
    ];

    // ===== Relasi =====

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function storeLocation()
    {
        // pastikan nama model & table StoreLocation sudah ada
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }

    // ===== Nomor PO Otomatis =====

    public static function nextNumber(): string
    {
        $prefix = 'PO-' . now()->format('Ym') . '-';
        $last   = static::where('purchase_number', 'like', $prefix . '%')
            ->max('purchase_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . sprintf('%04d', $seq);
    }
}
