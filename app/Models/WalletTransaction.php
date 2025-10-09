<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class WalletTransaction extends Model
{
    protected $fillable = [
        'customer_id',
        'order_id',
        'type',      // 'credit' | 'debit'
        'amount',    // stored as POSITIVE; sign comes from type
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Always store a positive amount (in case callers pass negatives)
    public function setAmountAttribute($value): void
    {
        $this->attributes['amount'] = abs((float) $value);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Signed value based on type (credit +, debit -). */
    public function getSignedAmountAttribute(): float
    {
        $amt = (float) $this->amount;
        return $this->type === 'debit' ? -$amt : $amt;
    }

    protected static function booted(): void
    {
        // When created: add to new customer's balance.
        static::created(function (WalletTransaction $tx) {
            if ($tx->customer_id) {
                self::applyDeltaToCustomer($tx->customer_id, $tx->signed_amount);
            }
        });

        // When updated: remove from old customer, add to (possibly) new customer.
        static::updated(function (WalletTransaction $tx) {
            $oldAmount = (float) ($tx->getOriginal('amount') ?? 0);
            $oldType   = $tx->getOriginal('type') ?? 'credit';
            $oldSigned = $oldType === 'debit' ? -$oldAmount : $oldAmount;

            $oldCustomerId = $tx->getOriginal('customer_id');
            $newCustomerId = $tx->customer_id;
            $newSigned     = $tx->signed_amount;

            if ($oldCustomerId && $oldCustomerId !== $newCustomerId) {
                // Remove old impact from old customer
                self::applyDeltaToCustomer($oldCustomerId, -$oldSigned);
                // Add new impact to new customer
                if ($newCustomerId) {
                    self::applyDeltaToCustomer($newCustomerId, $newSigned);
                }
            } else {
                // Same customer: just apply the difference
                $delta = $newSigned - $oldSigned;
                if ($newCustomerId && $delta != 0.0) {
                    self::applyDeltaToCustomer($newCustomerId, $delta);
                }
            }
        });

        // When deleted: reverse its effect from the (original) customer.
        static::deleted(function (WalletTransaction $tx) {
            $customerId = $tx->getOriginal('customer_id') ?? $tx->customer_id;
            if ($customerId) {
                self::applyDeltaToCustomer($customerId, -$tx->signed_amount);
            }
        });
    }

    /** Atomically adjust a customer's wallet_balance (NULL-safe). */
    private static function applyDeltaToCustomer(int $customerId, float $delta): void
    {
        DB::table('customers')
            ->where('id', $customerId)
            ->update([
                'wallet_balance' => DB::raw('ROUND(COALESCE(wallet_balance,0) + (' . ($delta) . '), 2)')
            ]);
    }
}
