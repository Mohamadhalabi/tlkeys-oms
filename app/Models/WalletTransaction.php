<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'order_id',
        'type',      // credit | debit
        'amount',    // positive number
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getSignedAmountAttribute(): float
    {
        $amt = (float) $this->amount;
        return $this->type === 'debit' ? -$amt : $amt;
    }

    protected static function booted(): void
    {
        static::created(function (WalletTransaction $tx) {
            $tx->applyDelta($tx->signed_amount);
        });

        static::updated(function (WalletTransaction $tx) {
            $oldAmount = (float) ($tx->getOriginal('amount') ?? 0);
            $oldType   = $tx->getOriginal('type') ?? 'credit';
            $oldSigned = $oldType === 'debit' ? -$oldAmount : $oldAmount;

            $delta = $tx->signed_amount - $oldSigned;
            $tx->applyDelta($delta);
        });

        static::deleted(function (WalletTransaction $tx) {
            $tx->applyDelta(-$tx->signed_amount);
        });
    }

    private function applyDelta(float $delta): void
    {
        if (! $this->customer_id) return;

        $customer = $this->customer()->first();
        if (! $customer) return;

        $customer->wallet_balance = (float) $customer->wallet_balance + $delta;
        $customer->saveQuietly();
    }
}
