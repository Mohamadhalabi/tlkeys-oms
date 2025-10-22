<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'address',
        'wallet_balance',
        'seller_id', // 👈 ensure this is fillable
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $c) {
            if (empty($c->code)) {
                $c->code = self::generateUniqueCode('TLKC', 6);
            }
            if (blank($c->seller_id) && auth()->user()?->hasRole('seller')) {
                $c->seller_id = auth()->id();
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

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function credit(float $amount, ?string $note = null, ?Order $order = null)
    {
        return $this->walletTransactions()->create([
            'order_id' => $order?->id,
            'type'     => 'credit',
            'amount'   => $amount,   // positive; events will +amount
            'note'     => $note,
        ]);
    }

    public function debit(float $amount, ?string $note = null, ?Order $order = null)
    {
        return $this->walletTransactions()->create([
            'order_id' => $order?->id,
            'type'     => 'debit',
            'amount'   => $amount,   // positive; events will -amount
            'note'     => $note,
        ]);
    }


    public function seller()
    {
        return $this->belongsTo(\App\Models\User::class, 'seller_id');
    }
    public function orders()
    {
        return $this->hasMany(\App\Models\Order::class);
    }
}
