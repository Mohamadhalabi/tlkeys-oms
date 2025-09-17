<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['name','email','phone','address','wallet_balance'];

    public function walletTransactions() 
    { 
        return $this->hasMany(WalletTransaction::class); 
    }

    public function credit(float $amount, ?string $ref=null, array $meta=[])
    {
        $this->increment('wallet_balance', $amount);
        return $this->walletTransactions()->create(['amount'=>$amount,'type'=>'credit','reference'=>$ref,'meta'=>$meta]);
    }

    public function debit(float $amount, ?string $ref=null, array $meta=[]) 
    {
        $this->decrement('wallet_balance', $amount);
        return $this->walletTransactions()->create(['amount'=>-1*$amount,'type'=>'debit','reference'=>$ref,'meta'=>$meta]);
    }
}
