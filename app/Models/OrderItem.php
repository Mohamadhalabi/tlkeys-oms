<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'sku', // ✅ Added SKU here
        'qty', 
        'product_name',
        'unit_price',
        'line_total',
        'sort',
        'note',
    ];

    protected $casts = [
        'qty'        => 'decimal:3', 
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item) {
            $item->line_total = round(((float)$item->qty) * ((float)$item->unit_price), 2);
        });

        static::updating(function (OrderItem $item) {
            if ($item->isDirty(['qty','unit_price'])) {
                $item->line_total = round(((float)$item->qty) * ((float)$item->unit_price), 2);
            }
        });
    }

    public function order()   { return $this->belongsTo(Order::class); }
    public function product() { return $this->belongsTo(Product::class); }
}