<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Order extends Model
{
    protected $fillable = [
        'code',
        'branch_id','customer_id','seller_id',
        'type','status','payment_status','paid_amount',
        'subtotal','discount','shipping','total',
        'currency','exchange_rate','extra_fees',
        'extra_fees_local',

    ];

    protected $casts = [
        'subtotal'      => 'decimal:2',
        'discount'      => 'decimal:2',
        'shipping'      => 'decimal:2',
        'total'         => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'paid_amount'   => 'decimal:2',
        'stock_state' => 'array',
        'extra_fees'        => 'decimal:4',
        'extra_fees_local'  => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->code)) {
                $order->code = self::generateUniqueCode('TLO', 6);
            }
            if (blank($order->seller_id) && auth()->check() && auth()->user()->hasAnyRole(['Seller','seller'])) {
                $order->seller_id = auth()->id();
            }
        });
    }

    protected static function generateUniqueCode(string $prefix, int $digits): string
    {
        do {
            $num  = str_pad((string) random_int(0, (10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
            $code = $prefix . $num;
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function items()
    {
        return $this->hasMany(\App\Models\OrderItem::class)->orderBy('sort');
    }

    public function branch()   { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function seller()   { return $this->belongsTo(User::class,'seller_id'); }

    // All wallet transactions (debit + credits) for this order
    public function walletTransactions() { return $this->hasMany(WalletTransaction::class); }
    // Only credits (payments)
    public function payments() { return $this->hasMany(WalletTransaction::class)->where('type', 'credit'); }

    protected function status(): Attribute
    {
        return Attribute::make(set: fn ($v) => $v === 'on-hold' ? 'on_hold' : $v);
    }

    public function recalcTotals(): void
    {
        $subtotal = (float) $this->items()->sum('line_total');
        $discount = (float) ($this->discount ?? 0);
        $shipping = (float) ($this->shipping ?? 0);

        $this->updateQuietly([
            'subtotal' => round($subtotal, 2),
            'total'    => round(max(0, $subtotal - $discount + $shipping), 2),
        ]);
    }

    /** Keep wallet in sync: one DEBIT for total, optional CREDIT for paid_amount */
    public function syncWallet(): void
    {
        // Proforma or missing customer => remove transactions tied to this order
        if ($this->type !== 'order' || ! $this->customer_id) {
            WalletTransaction::where('order_id', $this->id)->delete();
            return;
        }

        // 1) Order debit (amount owed)
        $debit = WalletTransaction::firstOrNew([
            'order_id'    => $this->id,
            'customer_id' => $this->customer_id,
            'type'        => 'debit',
            'note'        => 'Order ' . $this->code . ' total',
        ]);
        $debit->amount = (float) $this->total;
        $debit->save();

        // 2) Initial payment credit from header (paid_amount)
        $paid = (float) ($this->paid_amount ?? 0);
        if ($paid > 0) {
            $credit = WalletTransaction::firstOrNew([
                'order_id'    => $this->id,
                'customer_id' => $this->customer_id,
                'type'        => 'credit',
                'note'        => 'Initial payment for ' . $this->code,
            ]);
            $credit->amount = $paid;
            $credit->save();
        } else {
            WalletTransaction::where([
                'order_id'    => $this->id,
                'customer_id' => $this->customer_id,
                'type'        => 'credit',
                'note'        => 'Initial payment for ' . $this->code,
            ])->delete();
        }
    }
    
}
