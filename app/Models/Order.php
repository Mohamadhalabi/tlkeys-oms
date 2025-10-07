<?php
// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Order extends Model
{
    protected $fillable = [
        'code',           // ← new, human-friendly code (TLOxxxxxx)
        'branch_id','customer_id','seller_id','type','status',
        'subtotal','discount','shipping','total','currency','exchange_rate','payment_status'
    ];

    protected $casts = [
        'subtotal'      => 'decimal:2',
        'discount'      => 'decimal:2',
        'shipping'      => 'decimal:2',
        'total'         => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->code)) {
                $order->code = self::generateUniqueCode('TLO', 6);
            }
        });
    }

    /** Create a unique code like TLO123456 */
    protected static function generateUniqueCode(string $prefix, int $digits): string
    {
        do {
            $num  = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
            $code = $prefix . $num;
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function items()    { return $this->hasMany(OrderItem::class); }
    public function branch()   { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function seller()   { return $this->belongsTo(User::class,'seller_id'); }

    // Normalize hyphen statuses coming from the UI
    protected function status(): Attribute
    {
        return Attribute::make(
            set: fn ($v) => $v === 'on-hold' ? 'on_hold' : $v
        );
    }

    /** Recompute header totals from items (+ shipping − discount) */
    public function recalcTotals(): void
    {
        $subtotal = (float) $this->items()->sum('line_total');
        $discount = (float) ($this->discount ?? 0);
        $shipping = (float) ($this->shipping ?? 0);

        $this->updateQuietly([
            'subtotal' => round($subtotal, 2),
            'total'    => round($subtotal - $discount + $shipping, 2),
        ]);
    }
}
